<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsLearners;
use Tests\TestCase;

/**
 * The daily loop beyond the slice: Today -> session -> attempt -> evidence -> review -> completion.
 */
class DailyLoopTest extends TestCase
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

    private function startNextDaySession(): array
    {
        $this->at('2026-10-13T18:00:00Z');
        $res = $this->postJson('/v1/sessions', ['mode' => 'practise'])->assertCreated();

        return [$res->json('session.id'), collect($res->json('steps'))->keyBy('key'), $res];
    }

    public function test_topic_check_completes_the_topic_once_and_demonstrates_the_skill(): void
    {
        $user = $this->newUser();
        $this->claimSampleFor($user);
        [$sid, $steps] = $this->startNextDaySession();

        $check = $steps['review-0'];
        $this->assertSame('topic_check', $check['purpose']);
        $this->assertArrayNotHasKey('correct', $check['item']['options'][0]);

        $key = (string) Str::uuid();
        $first = $this->attempt($sid, $check, ['choice' => 'b'], $key)->assertCreated()->assertJsonPath('outcome', 'correct')->assertJsonPath('assistance', 'none');
        $this->assertSame([], $first->json('evidence_changes'), 'evidence waits for the learner to engage with the feedback');
        $this->attempt($sid, $check, ['choice' => 'b'], $key)->assertHeader('Idempotent-Replayed', 'true');
        $dup = $this->attempt($sid, $check, ['choice' => 'a'], (string) Str::uuid())->assertOk()->assertJsonPath('notice', 'already_answered');
        $this->assertSame('correct', $dup->json('outcome'), 'a second submission returns the first evaluation');
        $this->assertSame(1, DB::table('attempts')->where('session_id', $sid)->count());

        $this->assertSame(0, DB::table('skill_evidence')->whereIn('level', ['demonstrated'])->count());
        $this->saveDraft($sid, 1)->assertOk(); // moving past the feedback step records "feedback reviewed"

        $ev = DB::table('skill_evidence')->where('level', 'demonstrated')->first();
        $this->assertNotNull($ev, 'an unassisted check on a different scenario demonstrates the skill');
        $this->assertSame('auto_scored', $ev->basis);
        $track = DB::table('review_schedule')->first();
        $this->assertSame(2, (int) $track->interval_step);
        $this->assertSame('2026-10-20', $track->due_on, 'a passed topic check jumps to the 7-day rung');
        $this->assertNull($track->check_pending_topic_id);
        $this->assertSame('completed', DB::table('topic_progress')->join('roadmap_topics', 'roadmap_topics.id', '=', 'topic_progress.topic_id')->where('topic_key', 'perf.query-plans')->value('state'));

        // Finish the mission that was planned after the check.
        $answers = $this->correctAnswers();
        $idx = 0;
        foreach (['order-the-hops', 'find-the-failure', 'changed-condition'] as $k) {
            $this->attempt($sid, $steps[$k], $answers[$k])->assertCreated()->assertJsonPath('outcome', 'correct');
            $idx = $steps[$k]['index'] + 1;
        }
        $this->saveDraft($sid, $steps['changed-condition']['index'], 1)->assertOk();

        $ck = (string) Str::uuid();
        $done = $this->withHeader('Idempotency-Key', $ck)->postJson("/v1/sessions/{$sid}/complete", ['base_revision' => 2])->assertOk();
        $done->assertJsonPath('roadmap_progress.completed_required', 1)->assertJsonPath('roadmap_progress.total_required', 18)->assertJsonPath('roadmap_progress.percent', 5);
        $this->assertSame('perf.query-plans', $done->json('topic_changes.0.topic_key'));
        $levels = collect($done->json('summary.evidence_changes'))->pluck('level', 'skill_key');
        $this->assertSame('demonstrated', $levels['perf.query-plans']);
        $this->assertSame('practised', $levels['sys.request-path']);

        // Exactly once: the same key replays; a different key from another device changes nothing.
        $this->withHeader('Idempotency-Key', $ck)->postJson("/v1/sessions/{$sid}/complete", ['base_revision' => 2])->assertHeader('Idempotent-Replayed', 'true');
        $again = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/v1/sessions/{$sid}/complete", ['base_revision' => 2])->assertOk();
        $this->assertTrue($again->json('already_completed'));
        $this->assertSame(1, DB::table('analytics_events')->where('event_name', 'session_completed')->count());
        $this->assertSame(1, DB::table('analytics_events')->where('event_name', 'topic_completed')->count());
        $this->assertSame(1, DB::table('topic_progress')->where('state', 'completed')->count());
    }

    public function test_a_revealed_solution_never_demonstrates_and_schedules_a_fresh_attempt(): void
    {
        $user = $this->newUser();
        $this->claimSampleFor($user);
        [$sid, $steps] = $this->startNextDaySession();
        $check = $steps['review-0'];

        $this->postJson("/v1/sessions/{$sid}/reveal", ['step_index' => 0])->assertStatus(422); // needs the confirmation
        $this->postJson("/v1/sessions/{$sid}/reveal", ['step_index' => 0, 'confirm' => true])->assertOk()->assertJsonPath('recorded', true);
        $this->attempt($sid, $check, ['choice' => 'b'])->assertCreated()->assertJsonPath('assistance', 'solution_revealed');
        $this->saveDraft($sid, 1)->assertOk();

        $this->assertSame(0, DB::table('skill_evidence')->whereIn('level', ['demonstrated', 'retained'])->count());
        $this->assertSame('introduced', DB::table('skill_evidence')->where('assistance', 'solution_revealed')->value('level'));
        $this->assertNotSame('completed', DB::table('topic_progress')->join('roadmap_topics', 'roadmap_topics.id', '=', 'topic_progress.topic_id')->where('topic_key', 'perf.query-plans')->value('state'));
        $track = DB::table('review_schedule')->first();
        $this->assertSame(0, (int) $track->interval_step);
        $this->assertSame('2026-10-14', $track->due_on, 'a fresh attempt is scheduled for the next learner day');
    }

    public function test_one_hint_completes_the_check_but_does_not_demonstrate(): void
    {
        $user = $this->newUser();
        $this->claimSampleFor($user);
        [$sid, $steps] = $this->startNextDaySession();

        // Hint tiers open in order (HN-1): the ordering question has two hints, the check has one.
        $order = $steps['order-the-hops']['index'];
        $this->postJson("/v1/sessions/{$sid}/hints", ['step_index' => $order, 'hint_number' => 2])->assertStatus(409)->assertJsonPath('error.code', 'hint_locked');
        $this->postJson("/v1/sessions/{$sid}/hints", ['step_index' => 0, 'hint_number' => 2])->assertStatus(404);
        $this->postJson("/v1/sessions/{$sid}/hints", ['step_index' => 0, 'hint_number' => 1])->assertOk();
        $this->attempt($sid, $steps['review-0'], ['choice' => 'b'])->assertCreated()->assertJsonPath('assistance', 'hint');
        $this->saveDraft($sid, 1)->assertOk();

        $this->assertSame(0, DB::table('skill_evidence')->where('level', 'demonstrated')->count(), 'light assistance cannot demonstrate');
        $this->assertSame(1, DB::table('topic_progress')->where('state', 'completed')->count(), 'one hint still completes the check (TC-2)');
    }

    public function test_an_unmet_check_restarts_the_ladder_and_keeps_the_topic_open(): void
    {
        $user = $this->newUser();
        $this->claimSampleFor($user);
        [$sid, $steps] = $this->startNextDaySession();
        $this->attempt($sid, $steps['review-0'], ['choice' => 'a'])->assertCreated()->assertJsonPath('outcome', 'incorrect')->assertJsonPath('feedback.misconception', null);
        $this->saveDraft($sid, 1)->assertOk();

        $this->assertSame(0, DB::table('topic_progress')->where('state', 'completed')->count());
        $track = DB::table('review_schedule')->first();
        $this->assertSame(0, (int) $track->interval_step);
        $this->assertSame('2026-10-14', $track->due_on);
        $this->assertNotNull($track->check_pending_topic_id, 'the check stays pending and is retried with the other check-eligible item');
        // Next day the OTHER check-eligible item (Alternate A) is served.
        $this->at('2026-10-14T18:00:00Z');
        $this->postJson('/v1/sessions', ['mode' => 'practise', 'if_open' => 'suspend'])->assertCreated()
            ->assertJsonPath('steps.0.item.scenario', fn ($v) => str_contains($v, 'late parcels'));
    }

    public function test_starting_another_session_suspends_the_open_one_and_keeps_its_draft(): void
    {
        $user = $this->newUser();
        $this->claimSampleFor($user);
        [$sid, $steps] = $this->startNextDaySession();
        $this->saveDraft($sid, 1)->assertOk();

        $this->postJson('/v1/sessions', ['mode' => 'practise'])->assertOk()->assertJsonPath('resumed', true)->assertJsonPath('session.id', $sid);
        $small = $this->postJson('/v1/sessions', ['mode' => 'small', 'if_open' => 'suspend'])->assertCreated();
        $this->assertNotSame($sid, $small->json('session.id'));
        $this->assertSame('suspended', DB::table('learning_sessions')->where('id', $sid)->value('status'));
        $this->assertSame(1, DB::table('learning_sessions')->where('user_id', $user->id)->where('status', 'open')->count());
        $this->assertSame(1, (int) DB::table('session_drafts')->where('session_id', $sid)->value('revision'), 'the draft is kept');

        // The unfinished session stays reachable from Today; nothing is lost by switching (R05).
        $today = $this->getJson('/v1/today')->assertOk();
        $this->assertSame('resume', $today->json('situation'));
        $this->assertSame($small->json('session.id'), $today->json('primary_action.session_id'));
    }

    public function test_drafts_never_silently_overwrite_across_devices(): void
    {
        $user = $this->newUser();
        $this->claimSampleFor($user);
        [$sid] = $this->startNextDaySession();

        $a = (string) Str::uuid();
        $this->saveDraft($sid, 0, 0, $a)->assertOk()->assertJsonPath('revision', 1);
        $this->saveDraft($sid, 0, 0, $a)->assertOk()->assertJsonPath('revision', 1);              // a retry of the same save is replayed
        $this->saveDraft($sid, 0, 0)->assertStatus(409)->assertJsonPath('error.code', 'draft_conflict')
            ->assertJsonPath('error.details.server.revision', 1);                                    // another device with no revision
        $this->saveDraft($sid, 0, 1)->assertOk()->assertJsonPath('revision', 2);
        $this->saveDraft($sid, 0, 1)->assertStatus(409);                                             // stale base
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/v1/sessions/{$sid}/complete", ['base_revision' => 1])
            ->assertStatus(409)->assertJsonPath('error.code', 'draft_conflict');                     // complete must sync first
    }

    public function test_small_mode_is_a_curated_variant_and_does_not_complete_the_mission(): void
    {
        $user = $this->newUser();
        $this->claimSampleFor($user);
        $this->at('2026-10-13T18:00:00Z');
        $s = $this->postJson('/v1/sessions', ['mode' => 'small'])->assertCreated();
        // One due retrieval item is the small plan (SM-2): a single short check, never a truncated lesson.
        $this->assertCount(1, $s->json('steps'));
        $this->assertSame('topic_check', $s->json('steps.0.purpose'));
    }

    public function test_the_small_variant_of_a_mission_counts_as_practice_but_not_as_the_mission_attempt(): void
    {
        $user = $this->newUser();
        $this->claimSampleFor($user);
        // Clear the pending check so the small plan is the next mission's variant.
        DB::table('review_schedule')->update(['due_on' => '2026-12-01', 'check_pending_topic_id' => null]);
        $this->at('2026-10-13T18:00:00Z');
        $s = $this->postJson('/v1/sessions', ['mode' => 'small'])->assertCreated();
        $steps = collect($s->json('steps'))->keyBy('key');
        $this->assertSame(['scenario-short', 'order-the-hops'], array_keys($steps->all()));
        $this->assertSame('small_variant', $steps['order-the-hops']['purpose']);
        $this->attempt($s->json('session.id'), $steps['order-the-hops'], $this->correctAnswers()['order-the-hops'])->assertCreated();
        $this->saveDraft($s->json('session.id'), 1)->assertOk();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/v1/sessions/'.$s->json('session.id').'/complete', ['base_revision' => 1])->assertOk();

        $evidence = DB::table('skill_evidence')->where('skill_id', DB::table('skills')->where('skill_key', 'sys.request-path')->value('id'))->first();
        $this->assertSame('practised', $evidence->level);
        $this->assertContains('small_mode', json_decode($evidence->limitations, true), 'small-mode evidence is labelled as such');
        $progress = DB::table('topic_progress')->join('roadmap_topics', 'roadmap_topics.id', '=', 'topic_progress.topic_id')->where('topic_key', 'sys.request-path')->first();
        $this->assertNull($progress->mission_activity_done_at, 'a small variant does not satisfy the mission attempt (SM-4)');
    }
}
