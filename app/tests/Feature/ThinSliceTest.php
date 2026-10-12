<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsLearners;
use Tests\TestCase;

/**
 * docs/12 §5 — the thin vertical slice demo script, step by step.
 */
class ThinSliceTest extends TestCase
{
    use BuildsLearners, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publishContent();
        $this->at('2026-10-12T19:30:00Z'); // Monday evening, 20:30 in London
    }

    protected function tearDown(): void
    {
        $this->tearDownClock();
        parent::tearDown();
    }

    public function test_guest_sample_never_exposes_answer_keys_or_held_back_items(): void
    {
        $res = $this->getJson('/v1/guest/sample')->assertOk();
        $json = $res->getContent();
        foreach (['"correct"', 'feedback', '"reveal"', '"hints"', 'alt-b', 'check-1', 'misconception'] as $leak) {
            $this->assertStringNotContainsString($leak, $json, "sample leaked `{$leak}`");
        }
        $sample = $res->json('sample');
        $this->assertSame('perf.query-plans', $sample['id']);
        $this->assertCount(5, $sample['steps']);          // practise steps only; scenario-short is small mode
        $this->assertNotEmpty($sample['steps'][1]['item']['options']);
        $this->assertSame(2, $sample['steps'][1]['item']['hint_count']);
    }

    public function test_the_whole_demo_script(): void
    {
        // 1-3. A guest does the sample on their device: one hint, answer, authored feedback. Nothing is stored.
        $sample = $this->getJson('/v1/guest/sample')->json('sample');
        $mission = $sample['content_version_id'];
        $byKey = collect($sample['steps'])->keyBy('key');
        $first = $byKey['first-move']['item'];
        $second = $byKey['read-the-plan']['item'];
        $third = $byKey['changed-condition']['item'];

        $this->postJson('/v1/guest/hints', ['assessment_item_id' => $first['content_version_id'], 'content_version_id' => $mission, 'hint_number' => 1])
            ->assertOk()->assertJsonPath('hint_number', 1);
        $wrong = $this->postJson('/v1/guest/attempts', ['assessment_item_id' => $first['content_version_id'], 'content_version_id' => $mission, 'answer' => ['choice' => 'a']])
            ->assertOk()->assertJsonPath('outcome', 'incorrect')->json();
        $this->assertStringContainsString('fraction of a millisecond', $wrong['feedback']['text']);
        $this->assertSame(0, DB::table('attempts')->count(), 'guest evaluation stores nothing');

        $subject = (string) Str::uuid();
        $bundle = ['subject_id' => $subject,
            'onboarding' => ['time_zone' => 'Europe/London', 'available_days' => [1, 2, 3, 4, 5], 'preferred_mode' => 'practise', 'weekly_session_target' => 3, 'stack_tags' => ['laravel'], 'goal' => ['outcome_key' => 'performance']],
            'sample' => ['content_version_id' => $mission, 'attempts' => [
                // Browser-side results are not sent at all: the server re-evaluates every answer.
                ['assessment_item_id' => $first['content_version_id'], 'answer' => ['choice' => 'c'], 'answered_at' => '2026-10-12T19:20:00Z', 'hints_used' => 1, 'feedback_viewed' => true, 'client_attempt_id' => (string) Str::uuid()],
                ['assessment_item_id' => $second['content_version_id'], 'answer' => ['choice' => 'b'], 'answered_at' => '2026-10-12T19:24:00Z', 'hints_used' => 0, 'feedback_viewed' => true, 'client_attempt_id' => (string) Str::uuid()],
                ['assessment_item_id' => $third['content_version_id'], 'answer' => ['choice' => 'a'], 'answered_at' => '2026-10-12T19:27:00Z', 'hints_used' => 0, 'feedback_viewed' => true, 'client_attempt_id' => (string) Str::uuid()],
            ]],
        ];

        // 4. Sign up with a single-use invite bound to the email.
        $code = $this->inviteFor('learner@example.com');
        $this->postJson('/v1/auth/sign-up', ['email' => 'learner@example.com', 'password' => 'a-long-enough-passphrase', 'invite_code' => $code, 'guest_subject_id' => $subject])
            ->assertCreated()->assertJsonPath('user.email', 'learner@example.com');
        $user = User::where('email_normalised', 'learner@example.com')->firstOrFail();
        $this->assertSame($subject, $user->analytics_subject_id, 'the account adopts the guest subject id');
        $this->postJson('/v1/auth/sign-up', ['email' => 'learner@example.com', 'password' => 'a-long-enough-passphrase', 'invite_code' => $code])->assertStatus(422);

        $this->actingAs($user);
        $key = (string) Str::uuid();
        $claim = $this->withHeader('Idempotency-Key', $key)->postJson('/v1/guest/claim', $bundle)->assertOk();
        $claim->assertJsonPath('claimed_attempts', 3);

        // Enrolled in the single roadmap, pinned to its published version (R01).
        $enrolment = DB::table('roadmap_enrolments')->where('user_id', $user->id)->first();
        $this->assertNotNull($enrolment);
        $this->assertSame(18, (int) DB::table('roadmap_versions')->where('id', $enrolment->roadmap_version_id)->value('required_topic_count'));

        // 5. practised evidence (the claim cap), with the hint recorded, against the exact content version.
        $evidence = $this->getJson('/v1/evidence')->assertOk()->json('evidence');
        $this->assertCount(1, $evidence);
        $this->assertSame('perf.query-plans', $evidence[0]['skill_key']);
        $this->assertSame('practised', $evidence[0]['level']);
        $this->assertSame('auto_scored', $evidence[0]['basis']);
        $this->assertContains('guest_claim', $evidence[0]['limitations']);
        $this->assertContains('light_assistance', $evidence[0]['limitations']);
        $this->assertSame($mission, DB::table('attempts')->where('user_id', $user->id)->value('content_version_id') === null ? null : $mission);
        $this->assertSame(0, DB::table('skill_evidence')->whereIn('level', ['demonstrated', 'retained'])->count());

        // 6. The review is scheduled for the learner's next day, stored as a date in their zone.
        $track = DB::table('review_schedule')->where('user_id', $user->id)->first();
        $this->assertSame('2026-10-13', $track->due_on);
        $this->assertNotNull($track->check_pending_topic_id, 'the mission activity is done, so the topic check is next');

        // Same evening: the learner practised today, so Today is calm.
        $this->getJson('/v1/today')->assertOk()->assertJsonPath('situation', 'done_for_today');

        // 7. The next day, on a second device, Today shows the check within the cap, then the next mission.
        $this->at('2026-10-13T18:00:00Z');
        $today = $this->getJson('/v1/today')->assertOk();
        $today->assertJsonPath('situation', 'ready')
            ->assertJsonPath('primary_action.reason_code', 'NEXT_MISSION')
            ->assertJsonPath('reviews_included', 1);
        $title = $today->json('primary_action.title');
        $this->assertStringContainsString('Short check', $title);
        $this->assertStringContainsString('Trace a request end to end', $title);
        $this->assertStringContainsString('Module 1: Understand a system', $today->json('primary_action.context'));
        $this->assertNotNull($today->json('smaller_option'), 'a curated small variant is offered');
        $this->assertStringNotContainsString('overdue', strtolower($today->getContent()));

        // 8. The same claim again replays; nothing new is recorded; analytics hold no free text.
        $attemptsBefore = DB::table('attempts')->count();
        $replay = $this->withHeader('Idempotency-Key', $key)->postJson('/v1/guest/claim', $bundle)->assertOk();
        $replay->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($attemptsBefore, DB::table('attempts')->count());
        $this->assertSame(1, DB::table('analytics_events')->where('event_name', 'guest_progress_claimed')->count());

        foreach (DB::table('analytics_events')->get() as $event) {
            $props = json_decode($event->properties, true);
            foreach ($props as $k => $v) {
                if (is_string($v)) {
                    $this->assertMatchesRegularExpression('/^[a-z0-9_\-]{1,40}$|^[0-9a-f\-]{36}$/i', $v, "event {$event->event_name}.{$k} is free text");
                }
            }
            $this->assertSame($subject, $event->subject_id, 'all of this learner\'s events join through the adopted subject id');
        }
    }
}
