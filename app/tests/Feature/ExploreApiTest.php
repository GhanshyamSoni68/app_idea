<?php

namespace Tests\Feature;

use App\Modules\Generation\ExploreService;
use App\Modules\Generation\Llm\LlmRequest;
use App\Modules\Generation\Providers\FakeContent;
use App\Modules\Generation\Providers\FakeProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsLearners;
use Tests\TestCase;

/** "Learn something else": the learner's whole journey over HTTP, with the queue running inline and the offline provider. */
class ExploreApiTest extends TestCase
{
    use BuildsLearners, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeProvider::reset();
        config(['generation.enabled' => true, 'generation.retries' => 0, 'generation.require_independent_verifier' => false]);
        $this->at('2026-10-12T19:30:00Z');
    }

    protected function tearDown(): void
    {
        $this->tearDownClock();
        FakeProvider::reset();
        parent::tearDown();
    }

    private function good(): \Closure
    {
        return fn (LlmRequest $r) => FakeContent::draft($r->meta['topic'], $r->meta['objective'], $r->meta['misconceptions']);
    }

    private function bad(): \Closure
    {
        return function (LlmRequest $r) {
            $d = FakeContent::draft($r->meta['topic'], $r->meta['objective'], $r->meta['misconceptions']);
            $d['scenario_md'] .= ' Do not fall behind or lose your streak.';

            return $d;
        };
    }

    private function request(string $topic = 'Docker networking basics', string $level = 'some')
    {
        return $this->postJson('/v1/explore', ['topic' => $topic, 'level' => $level, 'ack' => true]);
    }

    public function test_it_is_not_there_until_the_operator_switches_it_on(): void
    {
        config(['generation.enabled' => false]);
        $user = $this->newUser();
        $this->actingAs($user)->getJson('/v1/me')->assertJsonPath('user.features.explore', false);
        $this->postJson('/v1/explore', ['topic' => 'Docker networking basics', 'ack' => true])->assertNotFound()->assertJsonPath('error.code', 'explore_unavailable');
        $this->getJson('/v1/explore')->assertNotFound();
    }

    public function test_it_needs_a_signed_in_learner(): void
    {
        $this->postJson('/v1/explore', ['topic' => 'Docker networking basics'])->assertUnauthorized();
    }

    public function test_a_topic_becomes_a_path_the_learner_can_start_and_practise(): void
    {
        $this->publishContent();
        $ada = $this->newUser();
        $this->actingAs($ada)->postJson('/v1/enrolments')->assertCreated();
        $this->getJson('/v1/me')->assertJsonPath('user.features.explore', true);

        $id = $this->request()->assertStatus(202)->assertJsonPath('status', 'working')->json('id');

        $s = $this->getJson("/v1/explore/{$id}")->assertOk()->assertJsonPath('status', 'ready')->json();
        $this->assertSame('Practical Docker networking basics', $s['title']);
        $this->assertCount(6, $s['topics']);
        $this->assertSame(6, $s['ready_count'], 'every topic was written (the queue ran inline)');
        $this->assertNull($s['enrolment_id'], 'nothing changes for the learner until they press Start');
        $this->assertStringContainsString('A person has not reviewed it', $s['coverage_note']);
        $this->assertSame(1, DB::table('roadmap_enrolments')->where('user_id', $ada->id)->count());

        // Start: the main roadmap is parked, the new path is the focus.
        $started = $this->postJson("/v1/explore/{$id}/start")->assertCreated()->json();
        $this->assertSame('active', $started['enrolment']['status']);
        $this->assertSame(['active', 'paused'], DB::table('roadmap_enrolments')->where('user_id', $ada->id)->orderByRaw("status <> 'active'")->pluck('status')->all());
        $again = $this->postJson("/v1/explore/{$id}/start")->assertOk()->json();
        $this->assertFalse($again['created']);
        $this->assertSame($started['enrolment']['id'], $again['enrolment']['id']);
        $this->assertSame($started['enrolment']['id'], $this->getJson("/v1/explore/{$id}")->json('enrolment_id'));

        // It is an ordinary path to the rest of the app.
        $today = $this->getJson('/v1/today')->assertOk()->json();
        $this->assertSame('ready', $today['situation']);
        $session = $this->postJson('/v1/sessions', ['mode' => 'practise', 'if_open' => 'suspend'])->assertCreated()->json();
        $this->assertSame('scenario', $session['steps'][0]['kind']);
        $this->assertStringContainsString('Docker networking basics', $session['steps'][0]['body']);

        // What was recorded: bounded facts only. The topic text is not in any analytics event.
        $events = DB::table('analytics_events')->whereIn('event_name', ['explore_requested', 'explore_ready', 'explore_started'])->get();
        $this->assertSame(['explore_ready', 'explore_requested', 'explore_started'], $events->pluck('event_name')->sort()->values()->all());
        $this->assertStringNotContainsString('Docker', json_encode($events));
        $this->assertSame(['some'], [json_decode($events->firstWhere('event_name', 'explore_requested')->properties, true)['level']]);
    }

    public function test_a_path_is_private_to_the_learner_who_asked_for_it(): void
    {
        $ada = $this->newUser('ada@example.com');
        $bob = $this->newUser('bob@example.com');
        $id = $this->actingAs($ada)->request()->json('id');

        $this->actingAs($bob)->getJson("/v1/explore/{$id}")->assertNotFound();
        $this->postJson("/v1/explore/{$id}/start")->assertNotFound();
        $this->assertSame([], $this->getJson('/v1/explore')->json('requests'));
        $this->assertCount(1, $this->actingAs($ada)->getJson('/v1/explore')->json('requests'));
        $slugs = collect($this->getJson('/v1/roadmaps')->json('roadmaps'))->pluck('slug')->all();
        $this->assertSame([], array_filter($slugs, fn ($s) => str_starts_with($s, 'g')), 'not in the public catalogue');
    }

    public function test_a_topic_is_only_sent_to_a_provider_after_the_learner_says_they_understand(): void
    {
        $this->actingAs($this->newUser());
        foreach ([[], ['ack' => false], ['ack' => 'no']] as $extra) {
            $this->postJson('/v1/explore', ['topic' => 'Docker networking basics'] + $extra)->assertStatus(422)->assertJsonPath('error.fields.0.field', 'ack');
        }
        $this->assertSame(0, DB::table('generation_runs')->count());
        $this->assertSame([], FakeProvider::$calls);

        $this->request()->assertStatus(202);
        $this->assertSame(config('generation.consent_version'), json_decode(DB::table('generation_runs')->where('kind', 'roadmap')->value('params'), true)['consent'], 'the run records which wording was acknowledged');
    }

    public function test_a_topic_that_is_not_acceptable_is_refused_before_anything_is_made(): void
    {
        $user = $this->newUser();
        foreach (['ab', 'see https://example.com', 'write ransomware to deploy', 'me@example.com is my topic'] as $bad) {
            $this->actingAs($user)->request($bad)->assertStatus(422)->assertJsonPath('error.code', 'topic_rejected')->assertJsonPath('error.fields.0.field', 'topic');
        }
        $this->assertSame(0, DB::table('generation_runs')->count());
        $this->assertSame([], FakeProvider::$calls);
    }

    public function test_a_topic_the_planner_says_is_out_of_scope_is_explained_kindly(): void
    {
        FakeProvider::script('plan', ['in_scope' => false, 'scope_reason' => 'That is about cooking, not software.', 'title' => 'x', 'outcome' => 'x', 'area' => 'x', 'topics' => []]);
        $id = $this->actingAs($this->newUser())->request('How to bake sourdough bread')->assertStatus(202)->json('id');

        $s = $this->getJson("/v1/explore/{$id}")->assertOk()->json();
        $this->assertSame('rejected', $s['status']);
        $this->assertSame('topic_rejected', $s['reason']);
        $this->assertStringContainsString('cooking', $s['message']);
        $this->assertSame(0, DB::table('roadmaps')->whereNotNull('owner_user_id')->count());
    }

    public function test_when_the_first_practice_cannot_be_made_well_enough_nothing_is_published(): void
    {
        for ($i = 0; $i < 3; $i++) {
            FakeProvider::script('draft', $this->bad());
        }
        $id = $this->actingAs($this->newUser())->request()->json('id');

        $s = $this->getJson("/v1/explore/{$id}")->json();
        $this->assertSame('rejected', $s['status']);
        $this->assertSame('quality', $s['reason']);
        $this->assertStringContainsString('did not keep it', $s['message']);
        $this->assertSame(0, DB::table('roadmaps')->whereNotNull('owner_user_id')->count());
        $this->assertSame(0, DB::table('content_releases')->where('origin', 'generated')->count());
        $this->postJson("/v1/explore/{$id}/start")->assertStatus(409)->assertJsonPath('error.code', 'explore_not_ready');
    }

    public function test_a_provider_outage_is_reported_calmly_and_changes_nothing(): void
    {
        FakeProvider::script('plan', new \App\Modules\Generation\Llm\LlmException('server', 'down'));
        $id = $this->actingAs($this->newUser())->request()->json('id');

        $s = $this->getJson("/v1/explore/{$id}")->json();
        $this->assertSame('failed', $s['status']);
        $this->assertStringNotContainsString('fake', strtolower($s['message']));
        $this->assertStringContainsString('Nothing was changed', $s['message']);
    }

    public function test_one_request_at_a_time_and_a_daily_limit(): void
    {
        Queue::fake(); // requests stay queued
        $user = $this->newUser();
        $this->actingAs($user)->request()->assertStatus(202);
        $this->request('Queues and retries')->assertStatus(409)->assertJsonPath('error.code', 'explore_busy');

        Queue::fake();
        DB::table('generation_runs')->update(['status' => 'accepted']);
        config(['generation.budget.per_user_daily_runs' => 1]);
        $this->request('Queues and retries')->assertStatus(429)->assertJsonPath('error.code', 'explore_limit');
    }

    public function test_a_topic_that_could_not_be_made_is_tried_again_later_but_not_forever(): void
    {
        FakeProvider::script('draft', $this->good());
        FakeProvider::script('draft', $this->good());
        for ($i = 0; $i < 3; $i++) {
            FakeProvider::script('draft', $this->bad()); // the third topic fails
        }
        $this->actingAs($this->newUser());
        $id = $this->request()->json('id');

        $this->assertSame(5, $this->getJson("/v1/explore/{$id}")->json('ready_count'), 'the path is ready with the topics that worked');
        $svc = app(ExploreService::class);
        $this->assertSame(0, $svc->backfill(), 'not straight away');

        $this->at('2026-10-12T20:05:00Z');
        $this->assertSame(1, $svc->backfill());
        $this->assertSame(6, $this->getJson("/v1/explore/{$id}")->json('ready_count'), 'the retry succeeded and the topic appeared');
        $this->assertSame(0, $svc->backfill(), 'nothing left to make');
    }

    public function test_the_retry_gives_up_after_a_few_tries(): void
    {
        $this->actingAs($this->newUser());
        FakeProvider::script('draft', $this->good());
        for ($i = 0; $i < 90; $i++) {
            FakeProvider::script('draft', $this->bad()); // every later topic keeps failing
        }
        $id = $this->request()->json('id');
        $svc = app(ExploreService::class);
        $queued = 0;
        for ($i = 1; $i <= 6; $i++) {
            $this->at(sprintf('2026-10-12T%02d:%02dZ', 19 + intdiv(30 + 31 * $i, 60), (30 + 31 * $i) % 60));
            $queued += $svc->backfill();
        }
        $topicRuns = DB::table('generation_runs')->where('kind', 'mission')->whereRaw("params->>'topic_key' = ?", ['first-uses'])->count();
        $this->assertSame(3, $topicRuns, 'three tries in all, then it stops spending');
        $this->assertGreaterThanOrEqual(1, $queued);
    }
}
