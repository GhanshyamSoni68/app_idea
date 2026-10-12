<?php

namespace Tests\Support;

use App\Models\User;
use App\Support\Clock;
use App\Support\Ids;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
}
