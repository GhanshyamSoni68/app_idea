<?php

namespace Tests\Support;

use App\Models\User;
use App\Support\Clock;
use App\Support\Ids;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Shared helpers: publish the real authored content, create learners, and control the clock. */
trait BuildsLearners
{
    protected function publishContent(): void
    {
        $code = Artisan::call('content:publish', ['--path' => base_path('../content'), '--dev' => true]);
        $this->assertSame(0, $code, Artisan::output());
    }

    protected function newUser(string $email = 'ada@example.com', string $tz = 'Europe/London', bool $onboard = true): User
    {
        $id = Ids::new();
        DB::insert(
            'INSERT INTO users (id, email, email_normalised, password_hash, analytics_subject_id, last_seen_at) VALUES (?, ?, ?, ?, ?, now())',
            [$id, $email, strtolower($email), Hash::make('correct horse battery'), Ids::new()],
        );
        if ($onboard) {
            DB::insert(
                "INSERT INTO learning_preferences (user_id, time_zone, available_days, preferred_mode, weekly_session_target) VALUES (?, ?, 127, 'practise', 3)",
                [$id, $tz],
            );
        }

        return User::findOrFail($id);
    }

    protected function inviteFor(string $email, string $code = 'ABCD-EFGH-JKLM'): string
    {
        DB::insert(
            'INSERT INTO invites (id, code_hash, email_normalised, expires_at) VALUES (?, ?, ?, ?)',
            [Ids::new(), hash('sha256', $code), strtolower($email), Clock::now()->addDays(10)],
        );

        return $code;
    }

    protected function at(string $instant): void
    {
        Clock::freeze($instant);
    }

    protected function tearDownClock(): void
    {
        Clock::freeze(null);
    }

    /** Sign in as the user and claim a guest bundle for the sample (hint on the first answer by default). */
    protected function claimSampleFor(User $user, array $firstHints = [1], string $at = '2026-10-12T19:30:00Z'): array
    {
        $sample = $this->getJson('/v1/guest/sample')->json('sample');
        $by = collect($sample['steps'])->keyBy('key');
        $bundle = ['subject_id' => (string) Str::uuid(),
            'onboarding' => ['time_zone' => 'Europe/London', 'available_days' => [1, 2, 3, 4, 5], 'preferred_mode' => 'practise', 'weekly_session_target' => 3, 'stack_tags' => ['laravel'], 'goal' => ['outcome_key' => 'performance']],
            'sample' => ['content_version_id' => $sample['content_version_id'], 'attempts' => [
                ['assessment_item_id' => $by['first-move']['item']['content_version_id'], 'answer' => ['choice' => 'c'], 'answered_at' => $at, 'hints_used' => count($firstHints), 'feedback_viewed' => true],
                ['assessment_item_id' => $by['read-the-plan']['item']['content_version_id'], 'answer' => ['choice' => 'b'], 'answered_at' => $at, 'hints_used' => 0, 'feedback_viewed' => true],
                ['assessment_item_id' => $by['changed-condition']['item']['content_version_id'], 'answer' => ['choice' => 'b'], 'answered_at' => $at, 'hints_used' => 0, 'feedback_viewed' => true],
            ]],
        ];
        $this->actingAs($user);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/v1/guest/claim', $bundle)->assertOk();

        return $bundle;
    }

    /** Answers that are correct for every response step of the two authored missions, by step key. */
    protected function correctAnswers(): array
    {
        return [
            'review-0' => ['choice' => 'b'],
            'order-the-hops' => ['order' => ['browser', 'checks', 'save', 'enqueue', 'respond', 'worker', 'mail-ask', 'mail-send']],
            'find-the-failure' => ['choice' => 'c'],
            'changed-condition' => ['choice' => 'a'],
        ];
    }

    protected function attempt(string $sessionId, array $step, array $answer, ?string $key = null, array $extra = [])
    {
        $session = DB::table('learning_sessions')->where('id', $sessionId)->first();

        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->postJson("/v1/sessions/{$sessionId}/attempts", [
            'step_index' => $step['index'], 'assessment_item_id' => $step['item']['content_version_id'],
            'content_version_id' => json_decode($session->plan, true)['steps'][$step['index']]['mission_cv'] ?? $step['item']['content_version_id'],
            'answer' => $answer,
        ] + $extra);
    }

    protected function saveDraft(string $sessionId, int $stepIndex, int $base = 0, ?string $saveId = null)
    {
        return $this->putJson("/v1/sessions/{$sessionId}/draft", [
            'base_revision' => $base, 'save_id' => $saveId ?? (string) Str::uuid(), 'step_index' => $stepIndex, 'data' => ['note' => 'x'], 'device_label' => 'Test browser',
        ]);
    }
}
