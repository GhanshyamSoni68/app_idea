<?php

namespace App\Modules\Identity;

use App\Models\User;
use App\Modules\Analytics\EventRecorder;
use App\Support\ApiException;
use App\Support\Clock;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * Account deletion (F09, PRD §12): a 7-day cancellable grace, then a hard delete by cascade that also removes that
 * learner's analytics events. A ledger kept OUTSIDE the main database records each request and purge, so a restore
 * from backup can re-apply purges that already happened (docs/04 §8.2).
 */
final class DeletionService
{
    public const GRACE_DAYS = 7;

    /** @return array<string, mixed> */
    public function request(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $existing = DB::selectOne("SELECT id, effective_at FROM deletion_requests WHERE user_ref = ? AND status IN ('pending', 'processing')", [$user->id]);
            if ($existing) {
                return ['id' => $existing->id, 'status' => 'pending', 'effective_at' => $existing->effective_at];
            }
            $now = Clock::now();
            $effective = $now->addDays(self::GRACE_DAYS);
            $id = Ids::new();
            $pilot = DB::selectOne('SELECT cohort_id, arm FROM invites WHERE redeemed_by = ?', [$user->id]);
            DB::insert(
                "INSERT INTO deletion_requests (id, user_ref, origin, status, requested_at, effective_at, pilot_cohort_id, pilot_arm) VALUES (?, ?, 'learner_request', 'pending', ?, ?, ?, ?)",
                [$id, $user->id, $now, $effective, $pilot->cohort_id ?? null, $pilot->arm ?? null],
            );
            DB::update("UPDATE users SET status = 'pending_deletion', updated_at = ? WHERE id = ?", [$now, $user->id]);
            self::ledger('requested', $user->id, $user->analytics_subject_id, $now->toIso8601String());
            DB::afterCommit(fn () => EventRecorder::server('account_deletion_requested', $user->analytics_subject_id, [], [], $id));

            return ['id' => $id, 'status' => 'pending', 'effective_at' => $effective->toIso8601String()];
        });
    }

    /** Cancel during the grace period; the account is restored exactly as it was. */
    public function cancel(User $user): void
    {
        DB::transaction(function () use ($user) {
            $won = DB::update("UPDATE deletion_requests SET status = 'cancelled', cancelled_at = ? WHERE user_ref = ? AND status = 'pending'", [Clock::now(), $user->id]);
            if ($won === 0) {
                throw ApiException::conflict('no_pending_deletion', 'There is no deletion waiting to be cancelled.');
            }
            DB::update("UPDATE users SET status = 'active', updated_at = ? WHERE id = ?", [Clock::now(), $user->id]);
            self::ledger('cancelled', $user->id, $user->analytics_subject_id, Clock::now()->toIso8601String());
        });
    }

    public function pending(User $user): ?object
    {
        return DB::selectOne("SELECT id, requested_at, effective_at FROM deletion_requests WHERE user_ref = ? AND status = 'pending'", [$user->id]);
    }

    /** Purge every account whose grace has ended. Returns how many. */
    public function purgeDue(): int
    {
        $n = 0;
        foreach (DB::select("SELECT id, user_ref FROM deletion_requests WHERE status = 'pending' AND effective_at <= ? ORDER BY effective_at", [Clock::now()]) as $req) {
            $claimed = DB::update("UPDATE deletion_requests SET status = 'processing' WHERE id = ? AND status = 'pending'", [$req->id]);
            if ($claimed === 0) {
                continue;
            }
            $user = DB::selectOne('SELECT id, analytics_subject_id FROM users WHERE id = ?', [$req->user_ref]);
            if ($user) {
                // Ledger first: if anything below fails or a restore brings the rows back, the purge is replayable.
                self::ledger('purged', $user->id, $user->analytics_subject_id, Clock::now()->toIso8601String());
                DB::transaction(function () use ($user) {
                    self::erase($user->id, $user->analytics_subject_id);
                });
            }
            DB::update("UPDATE deletion_requests SET status = 'completed', completed_at = ?, user_ref = NULL, steps = ?::jsonb WHERE id = ?", [Clock::now(), json_encode(['ledger' => true, 'analytics_events' => true, 'account' => true]), $req->id]);
            $n++;
        }

        return $n;
    }

    /** Hard delete: every learner row cascades from users; analytics events are keyed by subject and removed explicitly. */
    public static function erase(string $userId, string $subjectId): void
    {
        DB::delete('DELETE FROM analytics_events WHERE subject_id = ?', [$subjectId]);
        // Generation records keep cost and quality figures, but what the learner typed does not outlive the account (docs/16 §6).
        DB::update("UPDATE generation_runs SET topic = NULL, params = '{}'::jsonb WHERE user_id = ?", [$userId]);
        DB::delete('DELETE FROM generated_artifacts WHERE owner_user_id = ?', [$userId]);
        $paths = DB::table('roadmaps')->where('owner_user_id', $userId)->pluck('id')->all();
        DB::delete('DELETE FROM users WHERE id = ?', [$userId]);
        // The learner's own paths are not shared content: once their attempts and evidence are gone, nothing refers to them.
        (new \App\Modules\Generation\GeneratedCatalogue)->purgePaths($paths);
    }

    /** Re-apply every purge in the ledger, for use after restoring a backup taken before they happened. */
    public function replayLedger(): int
    {
        $path = self::ledgerPath();
        if (! is_file($path)) {
            return 0;
        }
        $n = 0;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $e = json_decode($line, true);
            if (($e['event'] ?? null) !== 'purged') {
                continue;
            }
            $exists = DB::selectOne('SELECT 1 FROM users WHERE id = ?', [$e['user_ref']]);
            $events = DB::selectOne('SELECT 1 FROM analytics_events WHERE subject_id = ? LIMIT 1', [$e['subject_id']]);
            if ($exists || $events) {
                DB::transaction(fn () => self::erase($e['user_ref'], $e['subject_id']));
                $n++;
            }
        }

        return $n;
    }

    public static function ledgerPath(): string
    {
        return (string) config('devstep.deletion_ledger_path', storage_path('app/deletion-ledger.jsonl'));
    }

    private static function ledger(string $event, string $userRef, string $subjectId, string $at): void
    {
        $path = self::ledgerPath();
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode(['event' => $event, 'user_ref' => $userRef, 'subject_id' => $subjectId, 'at' => $at])."\n", FILE_APPEND | LOCK_EX);
    }
}
