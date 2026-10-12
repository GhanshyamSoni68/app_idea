<?php

namespace App\Modules\Profile;

use App\Support\Clock;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/** `profile` module: learning preferences and the one active goal (F01). */
final class ProfileService
{
    public const STACKS = ['laravel', 'php', 'node', 'python', 'dotnet', 'java', 'ruby', 'go', 'other'];

    public const GOALS = ['performance', 'reliability', 'architecture', 'general', 'custom'];

    public const MODES = ['small', 'practise', 'build'];

    /** ISO weekday numbers (Mon=1..Sun=7) <-> the stored bitmask (bit 0 = Monday). */
    public static function daysToMask(array $days): int
    {
        $mask = 0;
        foreach (array_unique($days) as $d) {
            $mask |= 1 << ((int) $d - 1);
        }

        return $mask;
    }

    public static function maskToDays(int $mask): array
    {
        $days = [];
        for ($d = 1; $d <= 7; $d++) {
            if ($mask & (1 << ($d - 1))) {
                $days[] = $d;
            }
        }

        return $days;
    }

    public function preferences(string $userId): ?array
    {
        $row = DB::selectOne('SELECT * FROM learning_preferences WHERE user_id = ?', [$userId]);
        if (! $row) {
            return null;
        }

        return [
            'time_zone' => $row->time_zone,
            'available_days' => self::maskToDays($row->available_days),
            'preferred_mode' => $row->preferred_mode,
            'weekly_session_target' => $row->weekly_session_target,
            'weekly_lab_target' => $row->weekly_lab_target,
            'stack_tags' => json_decode($row->stack_tags, true),
            'diagnostic_status' => $row->diagnostic_status,
            'revision' => $row->revision,
        ];
    }

    /** Full replace (natural idempotency). Returns true when this was the first save (onboarding completed). */
    public function savePreferences(string $userId, array $p): bool
    {
        $now = Clock::now();
        $existed = DB::selectOne('SELECT 1 FROM learning_preferences WHERE user_id = ?', [$userId]) !== null;
        DB::statement(
            'INSERT INTO learning_preferences (user_id, time_zone, available_days, preferred_mode, weekly_session_target, stack_tags, diagnostic_status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?)
             ON CONFLICT (user_id) DO UPDATE SET time_zone = EXCLUDED.time_zone, available_days = EXCLUDED.available_days,
                preferred_mode = EXCLUDED.preferred_mode, weekly_session_target = EXCLUDED.weekly_session_target,
                stack_tags = EXCLUDED.stack_tags, revision = learning_preferences.revision + 1, updated_at = EXCLUDED.updated_at',
            [$userId, $p['time_zone'], self::daysToMask($p['available_days']), $p['preferred_mode'], $p['weekly_session_target'],
                json_encode(array_values($p['stack_tags'] ?? [])), $p['diagnostic_status'] ?? 'skipped', $now, $now],
        );

        return ! $existed;
    }

    /** Only fills preferences when the account has none (claim merge rule, docs/04 §7). */
    public function saveIfAbsent(string $userId, array $p): bool
    {
        if (DB::selectOne('SELECT 1 FROM learning_preferences WHERE user_id = ?', [$userId])) {
            return false;
        }

        return $this->savePreferences($userId, $p);
    }

    public function goal(string $userId): ?array
    {
        $g = DB::selectOne("SELECT outcome_key, note FROM goals WHERE user_id = ? AND status = 'active'", [$userId]);

        return $g ? ['outcome_key' => $g->outcome_key, 'note' => $g->note] : null;
    }

    /** One active goal; the previous one is kept as history (F01). */
    public function setGoal(string $userId, string $outcomeKey, ?string $note): void
    {
        $now = Clock::now();
        DB::transaction(function () use ($userId, $outcomeKey, $note, $now) {
            $current = DB::selectOne("SELECT id, outcome_key, note FROM goals WHERE user_id = ? AND status = 'active' FOR UPDATE", [$userId]);
            if ($current && $current->outcome_key === $outcomeKey && $current->note === $note) {
                return;
            }
            DB::update("UPDATE goals SET status = 'replaced', replaced_at = ?, updated_at = ? WHERE user_id = ? AND status = 'active'", [$now, $now, $userId]);
            DB::insert('INSERT INTO goals (id, user_id, outcome_key, note, status, created_at, updated_at) VALUES (?, ?, ?, ?, \'active\', ?, ?)', [Ids::new(), $userId, $outcomeKey, $note, $now, $now]);
        });
    }

    public function saveIfNoGoal(string $userId, string $outcomeKey, ?string $note): void
    {
        if (! DB::selectOne("SELECT 1 FROM goals WHERE user_id = ? AND status = 'active'", [$userId])) {
            $this->setGoal($userId, $outcomeKey, $note);
        }
    }
}
