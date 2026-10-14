<?php

namespace Tests\Feature;

use App\Modules\Catalogue\ContentPublisher;
use App\Modules\Catalogue\ContentValidator;
use App\Modules\Generation\GenerationException;
use App\Modules\Generation\GenerationService;
use App\Modules\Generation\Llm\LlmException;
use App\Modules\Generation\Llm\LlmRequest;
use App\Modules\Generation\Pipeline\ContentAssembler;
use App\Modules\Generation\ProviderManager;
use App\Modules\Generation\Providers\FakeContent;
use App\Modules\Generation\Providers\FakeProvider;
use App\Modules\Generation\RunTally;
use App\Modules\Roadmap\RoadmapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsLearners;
use Tests\TestCase;

/** The content engine end to end, offline: what is kept, what is refused, how it fails over, and what it logs. */
class GenerationEngineTest extends TestCase
{
    use BuildsLearners, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeProvider::reset();
        config(['generation.enabled' => true, 'generation.retries' => 1, 'generation.backoff_ms' => 0, 'generation.require_independent_verifier' => false]);
        config(['generation.providers.fake2' => ['driver' => 'fake', 'model' => 'fake-2']]);
    }

    protected function tearDown(): void
    {
        FakeProvider::reset();
        parent::tearDown();
    }

    private function manager(): ProviderManager
    {
        return new ProviderManager(config('generation'), fn () => null);
    }

    private function engine(): GenerationService
    {
        return GenerationService::make($this->manager());
    }

    private function http(string $name, string $host): void
    {
        config(["generation.providers.{$name}" => ['driver' => 'http', 'url' => "https://{$host}/complete", 'model' => "{$name}-model", 'price' => ['in' => 0, 'out' => 0]]]);
    }

    private function good(array $json, int $in = 100, int $out = 200): array
    {
        return ['json' => $json, 'model' => 'x', 'usage' => ['input_tokens' => $in, 'output_tokens' => $out]];
    }

    private function planAndDraft(string $topic = 'Caching basics', string $key = 'foundations'): array
    {
        $svc = $this->engine();
        $planned = $svc->planRoadmap(null, $topic);

        return [$svc, $planned, $svc->draftMission(null, $planned['run_id'], $planned, $key)];
    }

    public function test_a_topic_becomes_a_stored_quarantined_mission_and_every_call_is_logged(): void
    {
        [, $planned, $r] = $this->planAndDraft();

        $this->assertSame('accepted', $r['status']);
        $this->assertSame(0, $r['rounds']);
        $this->assertCount(6, $planned['plan']['topics']);
        $this->assertSame(2, DB::table('generation_runs')->where('status', 'accepted')->count());
        $this->assertSame(['quarantined'], DB::table('generated_artifacts')->pluck('status')->unique()->values()->all());
        $this->assertSame(['mission', 'roadmap_plan'], DB::table('generated_artifacts')->orderBy('kind')->pluck('kind')->all());
        $calls = DB::table('generation_calls')->get();
        $this->assertSame(['critique', 'draft', 'plan', 'solve'], $calls->pluck('task')->unique()->sort()->values()->all());
        $this->assertTrue($calls->every(fn ($c) => $c->ok && $c->provider === 'fake' && $c->request_hash && $c->prompt_version));
        $run = DB::table('generation_runs')->where('kind', 'mission')->first();
        $this->assertSame(config('generation.prompt_version'), $run->prompt_version);
        $this->assertNotNull($run->finished_at);
        $this->assertNull(DB::table('generation_calls')->whereNotNull('payload')->first(), 'prompts and replies are not stored unless payload logging is on');
    }

    public function test_a_disabled_engine_does_nothing(): void
    {
        config(['generation.enabled' => false]);
        $this->expectExceptionObject(GenerationException::disabled());
        $this->engine()->planRoadmap(null, 'Caching basics');
    }

    public function test_a_rule_breaking_draft_is_repaired_with_the_problems_quoted_back(): void
    {
        $seen = [];
        FakeProvider::script('draft', function (LlmRequest $r) {
            $d = FakeContent::draft($r->meta['topic'], $r->meta['objective'], $r->meta['misconceptions']);
            $d['scenario_md'] .= ' Read https://example.com now and do not fall behind.';

            return $d;
        });
        FakeProvider::script('draft', function (LlmRequest $r) use (&$seen) {
            $seen = $r->messages;

            return FakeContent::draft($r->meta['topic'], $r->meta['objective'], $r->meta['misconceptions']);
        });

        [, , $r] = $this->planAndDraft();

        $this->assertSame('accepted', $r['status']);
        $this->assertSame(1, $r['rounds']);
        $this->assertSame(1, (int) DB::table('generation_runs')->where('kind', 'mission')->value('repair_rounds'));
        $this->assertStringContainsString('Fix exactly these problems', $seen[0]['content']);
        $this->assertStringContainsString('Q02', $seen[0]['content']);
        $this->assertStringContainsString('Q04', $seen[0]['content']);
    }

    public function test_a_wrong_answer_key_is_caught_by_the_blind_solver_and_repaired(): void
    {
        FakeProvider::script('draft', function (LlmRequest $r) {
            $d = FakeContent::draft($r->meta['topic'], $r->meta['objective'], $r->meta['misconceptions']);
            foreach ($d['items'][0]['options'] as $i => $o) { // move the key to a different option
                $d['items'][0]['options'][$i]['correct'] = ! $o['correct'] && ! isset($moved) ? ($moved = true) : false;
            }

            return $d;
        });

        [, , $r] = $this->planAndDraft();

        $this->assertSame('accepted', $r['status']);
        $this->assertSame(1, $r['rounds'], 'the first draft had the wrong key and was repaired');
        $this->assertSame(2, collect(FakeProvider::$calls)->where('task', 'draft')->count());
    }

    public function test_a_critics_blocking_issue_sends_the_draft_back(): void
    {
        FakeProvider::script('critique', ['verdict' => 'revise', 'issues' => [['slot' => 'check-1', 'severity' => 'blocker', 'text' => 'The scenario states a claim that is only true on some versions.']], 'unsupported_claims' => []]);

        [, , $r] = $this->planAndDraft();

        $this->assertSame(1, $r['rounds']);
        $this->assertSame('accepted', $r['status']);
    }

    public function test_content_that_never_passes_is_rejected_and_nothing_is_kept(): void
    {
        for ($i = 0; $i < 3; $i++) {
            FakeProvider::script('draft', function (LlmRequest $r) {
                $d = FakeContent::draft($r->meta['topic'], $r->meta['objective'], $r->meta['misconceptions']);
                $d['worked_example_md'] .= ' Email ada@example.com for the key.';

                return $d;
            });
        }
        $svc = $this->engine();
        $planned = $svc->planRoadmap(null, 'Caching basics');
        $r = $svc->draftMission(null, $planned['run_id'], $planned, 'foundations');

        $this->assertSame('rejected', $r['status']);
        $this->assertSame(2, $r['rounds']);
        $this->assertSame(0, DB::table('generated_artifacts')->where('kind', 'mission')->count());
        $run = DB::table('generation_runs')->where('kind', 'mission')->first();
        $this->assertSame('rejected', $run->status);
        $this->assertSame('quality', $run->reason_code);
        $this->assertSame(3, collect(FakeProvider::$calls)->where('task', 'draft')->count());
    }

    public function test_an_out_of_scope_topic_is_refused_by_the_planner_and_recorded(): void
    {
        FakeProvider::script('plan', ['in_scope' => false, 'scope_reason' => 'That is about cooking, not software.', 'title' => 'x', 'outcome' => 'x', 'area' => 'x', 'topics' => []]);
        try {
            $this->engine()->planRoadmap(null, 'How to bake sourdough');
            $this->fail('should be refused');
        } catch (GenerationException $e) {
            $this->assertSame('topic_rejected', $e->code_);
            $this->assertStringContainsString('cooking', $e->getMessage());
        }
        $this->assertSame('rejected', DB::table('generation_runs')->value('status'));
        $this->assertSame(0, DB::table('generated_artifacts')->count());
    }

    public function test_a_topic_the_guard_refuses_costs_nothing_and_creates_no_run(): void
    {
        try {
            $this->engine()->planRoadmap(null, 'build a keylogger to deploy');
            $this->fail('should be refused');
        } catch (GenerationException $e) {
            $this->assertSame('topic_rejected', $e->code_);
        }
        $this->assertSame(0, DB::table('generation_runs')->count());
        $this->assertSame([], FakeProvider::$calls);
    }

    public function test_a_malformed_plan_is_retried_then_accepted(): void
    {
        FakeProvider::script('plan', ['in_scope' => true, 'scope_reason' => '', 'title' => 'T', 'outcome' => 'O', 'area' => 'x', 'topics' => []]);
        $planned = $this->engine()->planRoadmap(null, 'Caching basics');

        $this->assertCount(6, $planned['plan']['topics']);
        $this->assertSame(2, collect(FakeProvider::$calls)->where('task', 'plan')->count());
        $this->assertSame(1, DB::table('generation_calls')->where('task', 'plan')->where('ok', false)->where('error_kind', 'invalid_output')->count());
    }

    public function test_falls_back_to_the_next_provider_and_logs_the_failure(): void
    {
        $this->http('flaky', 'flaky.test');
        Http::fake(['flaky.test/*' => Http::response('boom', 500)]);
        config(['generation.routes.plan' => ['flaky', 'fake']]);

        $planned = $this->engine()->planRoadmap(null, 'Caching basics');

        $this->assertCount(6, $planned['plan']['topics']);
        $rows = DB::table('generation_calls')->where('task', 'plan')->orderBy('created_at')->get();
        $this->assertSame(['flaky', 'flaky', 'fake'], $rows->pluck('provider')->all(), 'tried twice (retries=1), then moved on');
        $this->assertSame([false, false, true], $rows->pluck('ok')->map(fn ($v) => (bool) $v)->all());
        $this->assertSame('server', $rows[0]->error_kind);
    }

    public function test_a_rate_limited_provider_is_retried_and_then_used(): void
    {
        $this->http('busy', 'busy.test');
        config(['generation.routes.plan' => ['busy']]);
        Http::fake(['busy.test/*' => Http::sequence()->push('slow down', 429, ['Retry-After' => '0'])->push($this->good(FakeContent::plan('Caching basics')))]);

        $planned = $this->engine()->planRoadmap(null, 'Caching basics');

        $this->assertCount(4, $planned['plan']['topics']);
        $rows = DB::table('generation_calls')->where('provider', 'busy')->orderBy('created_at')->get();
        $this->assertSame([1, 2], $rows->pluck('attempt')->all());
        $this->assertSame('rate_limit', $rows[0]->error_kind);
        $this->assertGreaterThan(0, $rows[1]->input_tokens);
    }

    public function test_an_invalid_reply_is_retried_with_the_problem_quoted_back(): void
    {
        $this->http('sloppy', 'sloppy.test');
        config(['generation.routes.plan' => ['sloppy']]);
        Http::fake(['sloppy.test/*' => Http::sequence()->push(['text' => 'Sure, here is a plan in prose.'])->push($this->good(FakeContent::plan('Caching basics')))]);

        $this->engine()->planRoadmap(null, 'Caching basics');

        $second = Http::recorded()[1][0]->data();
        $this->assertCount(3, $second['messages']);
        $this->assertStringContainsString('That reply was not valid', $second['messages'][2]['content']);
    }

    public function test_a_provider_that_keeps_failing_is_skipped_for_a_while(): void
    {
        $this->http('down', 'down.test');
        Http::fake(['down.test/*' => Http::response('', 503)]);
        config(['generation.routes.plan' => ['down', 'fake'], 'generation.retries' => 0, 'generation.circuit_threshold' => 2]);
        $svc = $this->engine();

        foreach (['One topic', 'Two topic', 'Three topic'] as $t) {
            $svc->planRoadmap(null, $t);
        }

        Http::assertSentCount(2); // two failures opened the circuit; the third run never touched the dead provider
        $health = $this->manager()->health();
        $this->assertTrue($health['down']['circuit_open']);
    }

    public function test_when_every_provider_fails_nothing_is_kept_and_the_learner_message_is_calm(): void
    {
        FakeProvider::script('plan', new LlmException(LlmException::SERVER, 'down'));
        FakeProvider::script('plan', new LlmException(LlmException::SERVER, 'down'));
        try {
            $this->engine()->planRoadmap(null, 'Caching basics');
            $this->fail('should fail');
        } catch (GenerationException $e) {
            $this->assertSame('unavailable', $e->code_);
            $this->assertStringNotContainsString('fake', strtolower($e->getMessage()), 'a learner never sees a vendor or model name');
        }
        $run = DB::table('generation_runs')->first();
        $this->assertSame('failed', $run->status);
        $this->assertSame(0, DB::table('generated_artifacts')->count());
    }

    public function test_budgets_stop_a_run_and_a_day(): void
    {
        $this->http('pricey', 'pricey.test');
        config(['generation.providers.pricey.price' => ['in' => 1000, 'out' => 1000], 'generation.routes.plan' => ['pricey']]);
        Http::fake(['pricey.test/*' => Http::response($this->good(FakeContent::plan('x'), 500, 500))]);
        $pm = $this->manager();
        $req = new LlmRequest('plan', 's', [['role' => 'user', 'content' => 'u']], null);

        $tally = new RunTally(null, 0.5);
        $pm->complete('plan', $req, $tally);
        $this->assertEqualsWithDelta(1.0, $tally->costUsd, 0.0001);
        try {
            $pm->complete('plan', $req, $tally);
            $this->fail('the run budget should stop the second call');
        } catch (GenerationException $e) {
            $this->assertSame('budget_run', $e->code_);
        }

        DB::table('generation_calls')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'task' => 'plan', 'provider' => 'x', 'model' => 'x', 'ok' => true, 'cost_usd' => 99, 'created_at' => now()]);
        try {
            $pm->complete('plan', $req, new RunTally);
            $this->fail('the daily budget should stop it');
        } catch (GenerationException $e) {
            $this->assertSame('budget_daily', $e->code_);
        }
    }

    public function test_a_learner_has_a_daily_limit(): void
    {
        config(['generation.budget.per_user_daily_runs' => 1]);
        $user = $this->newUser();
        $svc = $this->engine();
        $svc->planRoadmap($user->id, 'Caching basics');
        $this->expectExceptionObject(GenerationException::budget('user'));
        $svc->planRoadmap($user->id, 'Queues basics');
    }

    public function test_a_verifier_must_be_a_different_model_when_independence_is_required(): void
    {
        config(['generation.require_independent_verifier' => true]);
        $svc = $this->engine();
        $planned = $svc->planRoadmap(null, 'Caching basics');
        try {
            $svc->draftMission(null, $planned['run_id'], $planned, 'foundations');
            $this->fail('same-model verification must not be accepted');
        } catch (GenerationException $e) {
            $this->assertSame('verifier_not_independent', $e->code_);
        }
        $this->assertSame(0, DB::table('generated_artifacts')->where('kind', 'mission')->count());

        config(['generation.routes.verify' => ['fake2']]);
        $svc = $this->engine();
        $r = $svc->draftMission(null, $planned['run_id'], $planned, 'foundations');
        $this->assertSame('accepted', $r['status']);
        $this->assertTrue($r['independent']);
        $quality = json_decode(DB::table('generated_artifacts')->where('kind', 'mission')->value('quality'), true);
        $this->assertSame('verified_independent', $quality['tier']);
        $this->assertSame('fake-2', $quality['verification']['solver']['model']);
        $this->assertSame('fake-1', $quality['drafted_by']['model']);
    }

    public function test_the_fake_provider_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->assertNotNull((new FakeProvider('fake'))->problem());
    }

    public function test_generated_content_publishes_and_runs_in_the_existing_learning_engine(): void
    {
        $svc = $this->engine();
        $planned = $svc->planRoadmap(null, 'Caching basics');
        $missions = [];
        foreach ($planned['plan']['topics'] as $t) {
            $r = $svc->draftMission(null, $planned['run_id'], $planned, $t['key']);
            $this->assertSame('accepted', $r['status'], $t['key']);
            $missions[$r['mission']['front']['id']] = $r['mission'];
        }
        $bundle = ContentAssembler::bundle($planned['roadmap'], $missions);

        $findings = (new ContentValidator)->validate($bundle, ['allow_unreviewed' => true, 'allow_unsourced' => true]);
        $this->assertSame([], array_values(array_filter($findings, fn ($f) => $f['severity'] === 'error')));

        $stats = (new ContentPublisher)->publish($bundle, 'generation-test');
        $this->assertSame(6, $stats['missions']);
        $this->assertSame(36, $stats['items']);
        $this->assertSame(1, $stats['roadmap_versions']);

        $user = $this->newUser();
        [$enrolment] = app(RoadmapService::class)->enrol($user->id, (string) $user->analytics_subject_id, $planned['roadmap']['slug']);
        $view = app(RoadmapService::class)->view($enrolment);
        $this->assertSame(6, $view['progress']['total_required']);

        // The first topic can be started and its first question answered by the unchanged session engine.
        $this->actingAs($user);
        $today = $this->getJson('/v1/today')->assertOk()->json();
        $this->assertSame('ready', $today['situation']);
        $session = $this->withHeader('Idempotency-Key', (string) \Illuminate\Support\Str::uuid())->postJson('/v1/sessions', ['mode' => 'practise', 'if_open' => 'suspend'])->assertSuccessful()->json('session');
        $payload = $this->getJson("/v1/sessions/{$session['id']}")->assertOk()->json();
        $this->assertSame('scenario', $payload['steps'][0]['kind']);
        $this->assertStringContainsString('Caching basics', $payload['steps'][0]['body']);
    }

    public function test_deleting_an_account_erases_what_the_learner_typed_but_keeps_the_cost_record(): void
    {
        $user = $this->newUser();
        $svc = $this->engine();
        $planned = $svc->planRoadmap($user->id, 'My private topic idea');
        $svc->draftMission($user->id, $planned['run_id'], $planned, 'foundations');
        $this->assertSame(2, DB::table('generation_runs')->where('user_id', $user->id)->whereNotNull('topic')->count());

        \App\Modules\Identity\DeletionService::erase($user->id, (string) $user->analytics_subject_id);

        $this->assertSame(0, DB::table('generated_artifacts')->count());
        $this->assertSame(2, DB::table('generation_runs')->whereNull('user_id')->whereNull('topic')->count(), 'the runs stay for cost and quality, without the learner or their words');
        $this->assertGreaterThan(0, DB::table('generation_calls')->count());
    }

    public function test_old_model_payloads_are_purged_but_the_call_record_stays(): void
    {
        config(['generation.log_payloads' => true]);
        $this->engine()->planRoadmap(null, 'Caching basics');
        $row = DB::table('generation_calls')->first();
        $this->assertNotNull($row->payload);
        $this->assertStringNotContainsString('ANTHROPIC', $row->payload);

        $this->assertSame(0, \App\Modules\Generation\GenerationLog::purgePayloads(), 'recent payloads are kept');
        $this->travel(31)->days();
        $this->assertSame(1, \App\Modules\Generation\GenerationLog::purgePayloads());
        $this->assertNull(DB::table('generation_calls')->first()->payload);
        $this->assertSame(1, DB::table('generation_calls')->count());
    }
}
