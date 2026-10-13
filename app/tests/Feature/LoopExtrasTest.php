<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsLearners;
use Tests\TestCase;

/**
 * R05 pause and resume, R03 defer and challenge-out, F06 return after absence (docs/05 §7, §10).
 */
class LoopExtrasTest extends TestCase
{
    use BuildsLearners, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publishContent();
        $this->at('2026-10-12T19:30:00Z');
    }

    protected function tearDown(): void
    {
        $this->tearDownClock();
        parent::tearDown();
    }

    private function topicId(string $key): string
    {
        return DB::table('roadmap_topics')->where('topic_key', $key)->value('id');
    }

    private function enrolmentId(): string
    {
        return DB::table('roadmap_enrolments')->value('id');
    }

    private function finish(string $sessionId, int $rev = 0)
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/v1/sessions/{$sessionId}/complete", ['base_revision' => $rev]);
    }

    public function test_pausing_keeps_everything_and_resuming_shifts_reviews_with_no_debt(): void
    {
        $user = $this->newUser();
        $this->claimSampleFor($user);
        $this->assertSame('2026-10-13', DB::table('review_schedule')->value('due_on'));

        $this->patchJson('/v1/enrolments/'.$this->enrolmentId(), ['status' => 'paused'])->assertOk()->assertJsonPath('enrolment.status', 'paused');
        $this->assertSame('suspended', DB::table('review_schedule')->value('status'));
        $this->at('2026-10-14T10:00:00Z');
        $today = $this->getJson('/v1/today')->assertOk();
        $today->assertJsonPath('situation', 'paused')->assertJsonPath('primary_action.reason_code', 'PAUSED');
        $this->postJson('/v1/sessions', ['mode' => 'practise'])->assertStatus(409)->assertJsonPath('error.code', 'nothing_to_start');
        $this->assertSame(1, DB::table('topic_progress')->where('state', 'in_progress')->count(), 'topic states are kept');

        // Five days later: resuming shifts the review by the days paused, so there is no catch-up pile.
        $this->at('2026-10-19T10:00:00Z');
        $this->patchJson('/v1/enrolments/'.$this->enrolmentId(), ['status' => 'active'])->assertOk()->assertJsonPath('enrolment.status', 'active');
        $track = DB::table('review_schedule')->first();
        $this->assertSame('active', $track->status);
        $this->assertSame('2026-10-20', $track->due_on, 'due 13 Oct + the 7 days paused (12 to 19 Oct): it simply moves, nothing piles up');
        $this->assertSame(1, $this->getJson('/v1/today')->assertOk()->json('reviews_included') ?? 0, 'at most the capped review');
        $this->assertSame(1, DB::table('analytics_events')->where('event_name', 'enrolment_resumed')->count());
    }

    public function test_deferring_earns_no_credit_and_a_blocking_deferral_resurfaces_calmly(): void
    {
        $user = $this->newUser();
        $this->claimSampleFor($user);
        $topic = $this->topicId('sys.request-path');

        $this->postJson("/v1/topics/{$topic}/defer")->assertOk()->assertJsonPath('topic.state', 'deferred')->assertJsonPath('topic.credit', false);
        $this->postJson("/v1/topics/{$topic}/defer")->assertOk(); // natural idempotency
        $this->assertSame(0, DB::table('topic_progress')->where('state', 'completed')->count());
        $this->postJson('/v1/topics/'.$this->topicId('perf.query-plans').'/defer')->assertOk(); // in progress is deferrable

        // Nothing else is ready and nothing is due: the deferred topic that blocks the rest is offered once.
        DB::table('review_schedule')->update(['due_on' => '2027-01-01']);
        $this->at('2026-10-13T10:00:00Z');
        $today = $this->getJson('/v1/today')->assertOk();
        $today->assertJsonPath('primary_action.reason_code', 'DEFERRED_BLOCKER');
        $this->assertStringContainsString('unlocks the next', $today->json('primary_action.rationale'));
        $this->assertStringContainsString('take the challenge', $today->json('primary_action.rationale'));

        // Starting it brings it back to in_progress; bringing back a never-started topic restores not_started.
        $this->postJson('/v1/sessions', ['mode' => 'practise'])->assertCreated();
        $this->assertSame('in_progress', DB::table('topic_progress')->where('topic_id', $topic)->value('state'));
        $other = $this->topicId('perf.latency-evidence');
        $this->postJson("/v1/topics/{$other}/defer")->assertOk();
        $this->deleteJson("/v1/topics/{$other}/defer")->assertOk()->assertJsonPath('topic.state', 'not_started');
    }

    public function test_a_completed_topic_cannot_be_deferred(): void
    {
        $user = $this->newUser();
        $this->claimSampleFor($user);
        DB::statement("UPDATE topic_progress SET state = 'completed', completed_at = now(), completion_path = 'carried_over', carried_from_progress_id = id WHERE topic_id = ?", [$this->topicId('perf.query-plans')]);
        $this->postJson('/v1/topics/'.$this->topicId('perf.query-plans').'/defer')->assertStatus(409)->assertJsonPath('error.code', 'topic_completed');
    }

    public function test_passing_a_challenge_completes_the_topic_and_demonstrates_the_skill_once(): void
    {
        $user = $this->newUser();
        $this->claimSampleFor($user);
        $topic = $this->topicId('sys.request-path');

        $view = $this->getJson('/v1/enrolments/'.$this->enrolmentId())->assertOk();
        $flags = collect($view->json('modules'))->pluck('topics')->flatten(1)->keyBy('topic_key');
        $this->assertTrue($flags['sys.request-path']['challenge_available']);
        $this->assertFalse($flags['sys.requirements']['challenge_available'], 'no content, so no challenge');

        $s = $this->postJson("/v1/topics/{$topic}/challenge")->assertCreated();
        $sid = $s->json('session.id');
        $steps = collect($s->json('steps'));
        $this->assertCount(2, $steps);
        $this->assertSame(['challenge', 'challenge'], $steps->pluck('purpose')->all());
        $this->postJson("/v1/sessions/{$sid}/hints", ['step_index' => 0, 'hint_number' => 1])->assertStatus(409)->assertJsonPath('error.code', 'assistance_disabled');
        $this->postJson("/v1/sessions/{$sid}/reveal", ['step_index' => 0, 'confirm' => true])->assertStatus(409);
        $this->assertSame('not_started', DB::table('topic_progress')->where('topic_id', $topic)->value('state'), 'starting a challenge changes nothing (TP-1)');

        foreach ($steps as $step) {
            $this->attempt($sid, $step, ['choice' => 'b'])->assertCreated()->assertJsonPath('outcome', 'correct');
        }
        $done = $this->finish($sid)->assertOk();
        $done->assertJsonPath('challenge.passed', true)->assertJsonPath('roadmap_progress.completed_required', 1);
        $this->assertSame('challenge_out', DB::table('topic_progress')->where('topic_id', $topic)->value('completion_path'));
        $this->assertSame('demonstrated', DB::table('skill_evidence')->join('skills', 'skills.id', '=', 'skill_evidence.skill_id')->where('skill_key', 'sys.request-path')->value('level'));
        $track = DB::table('review_schedule')->join('skills', 'skills.id', '=', 'review_schedule.skill_id')->where('skill_key', 'sys.request-path')->first();
        $this->assertSame(2, (int) $track->interval_step);
        $this->assertSame('2026-10-19', $track->due_on, 'the next check falls 7 days later (CH-3)');
        $this->assertSame(1, DB::table('analytics_events')->where('event_name', 'topic_completed')->count());

        // One attempt per topic: it cannot be taken again, and the roadmap no longer offers it.
        $this->postJson("/v1/topics/{$topic}/challenge")->assertStatus(409)->assertJsonPath('error.code', 'challenge_unavailable');
    }

    public function test_failing_a_challenge_leaves_the_topic_alone_and_only_earns_practised(): void
    {
        $user = $this->newUser();
        $this->claimSampleFor($user);
        $topic = $this->topicId('sys.request-path');
        $s = $this->postJson("/v1/topics/{$topic}/challenge")->assertCreated();
        $sid = $s->json('session.id');
        $steps = collect($s->json('steps'));
        $this->attempt($sid, $steps[0], ['choice' => 'b'])->assertCreated();
        $this->attempt($sid, $steps[1], ['choice' => 'a'])->assertCreated()->assertJsonPath('outcome', 'incorrect');
        $done = $this->finish($sid)->assertOk();

        $done->assertJsonPath('challenge.passed', false)->assertJsonPath('roadmap_progress.completed_required', 0);
        $this->assertNotSame('completed', DB::table('topic_progress')->where('topic_id', $topic)->value('state'));
        $levels = DB::table('skill_evidence')->join('skills', 'skills.id', '=', 'skill_evidence.skill_id')->where('skill_key', 'sys.request-path')->pluck('level')->all();
        $this->assertSame(['practised', 'practised'], $levels, 'a failed challenge never demonstrates, even for the item that was met');
        $this->assertSame(0, DB::table('review_schedule')->join('skills', 'skills.id', '=', 'review_schedule.skill_id')->where('skill_key', 'sys.request-path')->count(), 'a failed challenge creates no review item (RV-1)');
        $this->postJson("/v1/topics/{$topic}/challenge")->assertStatus(409);
    }

    public function test_returning_after_a_long_absence_offers_a_short_refresher_without_catch_up_or_guilt(): void
    {
        $user = $this->newUser();
        $this->claimSampleFor($user);
        $this->at('2026-10-22T18:00:00Z'); // ten days later

        $today = $this->getJson('/v1/today')->assertOk();
        $today->assertJsonPath('situation', 'returning')->assertJsonPath('primary_action.reason_code', 'RETURN_REFRESHER');
        $this->assertSame('A 3-minute refresher', $today->json('primary_action.title'));
        $this->assertStringContainsString('Welcome back', $today->json('primary_action.rationale'));
        $this->assertSame('Read a query plan before adding servers', $today->json('welcome_back.last_work.title'));
        $this->assertNotEmpty($today->json('secondary_actions'), 'the normal plan stays one tap away');
        $this->assertDoesNotMatchRegularExpression('/missed|behind|overdue|streak|lost/i', json_encode($today->json('primary_action')));
        $this->assertSame(1, $today->json('reviews_included'), 'one item, not a backlog');

        $s = $this->postJson('/v1/sessions', ['mode' => 'small', 'origin' => 'return_screen'])->assertCreated();
        $this->assertSame('recovery', $s->json('session.purpose'));
        $this->assertCount(1, $s->json('steps'));
        $sid = $s->json('session.id');
        $this->attempt($sid, $s->json('steps.0'), ['choice' => 'b'])->assertCreated()->assertJsonPath('outcome', 'correct');
        $this->finish($sid, 0)->assertOk();

        // Offered once: the next Today is the ordinary one, and nothing doubled up.
        $after = $this->getJson('/v1/today')->assertOk();
        $this->assertNotSame('returning', $after->json('situation'));
    }
}
