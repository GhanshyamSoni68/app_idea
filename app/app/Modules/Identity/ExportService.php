<?php

namespace App\Modules\Identity;

use App\Models\User;
use App\Modules\Analytics\EventRecorder;
use App\Modules\Profile\ProfileService;
use App\Support\ApiException;
use App\Support\Clock;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PDO;
use ZipArchive;

/**
 * Account export (F09): a versioned ZIP of the learner's own data, generated into Postgres (no object storage at the
 * pilot) and downloaded through a short-lived authenticated link. Excluded: password hash, token hashes, idempotency
 * keys, and authored content bodies and answer keys (referenced by key and version only). docs/09 §8.
 */
final class ExportService
{
    public const FORMAT_VERSION = 1;

    private const TTL_HOURS = 24;

    /** @return array<string, mixed> */
    public function request(User $user): array
    {
        $id = Ids::new();
        $now = Clock::now();
        $open = DB::selectOne("SELECT id FROM export_requests WHERE user_id = ? AND status IN ('queued', 'running')", [$user->id]);
        if ($open) {
            return $this->status($user, $open->id);
        }
        DB::insert("INSERT INTO export_requests (id, user_id, status, format_version, created_at) VALUES (?, ?, 'running', ?, ?)", [$id, $user->id, self::FORMAT_VERSION, $now]);

        try {
            $zip = $this->build($user);
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $zip);
            rewind($stream);
            $stmt = DB::connection()->getPdo()->prepare(
                "UPDATE export_requests SET status = 'ready', file = :f, byte_size = :s, sha256 = :h, expires_at = :e, completed_at = :c WHERE id = :id",
            );
            $size = strlen($zip);
            $hash = hash('sha256', $zip);
            $expires = $now->addHours(self::TTL_HOURS)->toDateTimeString();
            $completed = $now->toDateTimeString();
            $stmt->bindParam(':f', $stream, PDO::PARAM_LOB);
            $stmt->bindParam(':s', $size, PDO::PARAM_INT);
            $stmt->bindParam(':h', $hash);
            $stmt->bindParam(':e', $expires);
            $stmt->bindParam(':c', $completed);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
        } catch (\Throwable $e) {
            DB::update("UPDATE export_requests SET status = 'failed', completed_at = ? WHERE id = ?", [$now, $id]);
            throw new ApiException('export_failed', 'We could not prepare your export. Please try again.', 500, true);
        }
        DB::afterCommit(fn () => EventRecorder::server('export_requested', $user->analytics_subject_id, [], [], $id));

        return $this->status($user, $id);
    }

    /** @return array<string, mixed> */
    public function status(User $user, string $id): array
    {
        $row = DB::selectOne('SELECT id, status, byte_size, expires_at, created_at FROM export_requests WHERE id = ? AND user_id = ?', [$id, $user->id]);
        if (! $row) {
            throw ApiException::notFound('That export');
        }
        $ready = $row->status === 'ready' && CarbonImmutable::parse($row->expires_at)->greaterThan(Clock::now());

        return [
            'id' => $row->id,
            'status' => $row->status === 'ready' && ! $ready ? 'expired' : $row->status,
            'byte_size' => $row->byte_size,
            'expires_at' => $row->expires_at,
            'download_url' => $ready ? "/v1/me/export/{$row->id}/download" : null,
        ];
    }

    /** @return array{0: string, 1: string} [filename, bytes] */
    public function download(User $user, string $id): array
    {
        $row = DB::selectOne("SELECT file, expires_at FROM export_requests WHERE id = ? AND user_id = ? AND status = 'ready'", [$id, $user->id]);
        if (! $row || CarbonImmutable::parse($row->expires_at)->lessThanOrEqualTo(Clock::now())) {
            throw ApiException::notFound('That export');
        }
        $bytes = is_resource($row->file) ? stream_get_contents($row->file) : (string) $row->file;

        return ['devstep-export-'.Clock::now()->format('Ymd').'.zip', $bytes];
    }

    /** Clear files past their expiry; the row stays as a record that an export happened. */
    public function purgeExpired(): int
    {
        return DB::update("UPDATE export_requests SET status = 'expired', file = NULL WHERE status = 'ready' AND expires_at <= ?", [Clock::now()]);
    }

    /** Weekdays as numbers 1 (Monday) to 7 (Sunday), not the stored bitmask. */
    private function preferences(?array $p): ?array
    {
        if ($p) {
            $p['available_days'] = ProfileService::maskToDays((int) $p['available_days']);
        }

        return $p;
    }

    private function build(User $user): string
    {
        $uid = $user->id;
        $q = fn (string $sql) => array_map(fn ($r) => (array) $r, DB::select($sql, [$uid]));
        $json = fn (array $rows, array $cols) => array_map(function ($r) use ($cols) {
            foreach ($cols as $c) {
                if (isset($r[$c]) && is_string($r[$c])) {
                    $r[$c] = json_decode($r[$c], true);
                }
            }

            return $r;
        }, $rows);

        $account = [
            'format_version' => self::FORMAT_VERSION,
            'exported_at' => Clock::now()->toIso8601String(),
            'account' => ['id' => $user->id, 'email' => $user->email, 'email_verified_at' => $user->email_verified_at?->toIso8601String(), 'created_at' => $user->created_at?->toIso8601String()],
            'preferences' => $this->preferences($json($q('SELECT time_zone, available_days, preferred_mode, weekly_session_target, stack_tags, diagnostic_status, created_at FROM learning_preferences WHERE user_id = ?'), ['stack_tags'])[0] ?? null),
            'goals' => $q('SELECT outcome_key, note, status, created_at, replaced_at FROM goals WHERE user_id = ? ORDER BY created_at'),
        ];
        $learning = [
            'format_version' => self::FORMAT_VERSION,
            'enrolments' => $q('SELECT e.id, r.slug AS roadmap, rv.version AS roadmap_version, e.status, e.enrolled_at, e.paused_at, e.completed_at FROM roadmap_enrolments e JOIN roadmap_versions rv ON rv.id = e.roadmap_version_id JOIN roadmaps r ON r.id = e.roadmap_id WHERE e.user_id = ? ORDER BY e.enrolled_at'),
            'topic_progress' => $q('SELECT t.topic_key, tp.state, tp.completion_path, tp.started_at, tp.completed_at, tp.deferred_at FROM topic_progress tp JOIN roadmap_topics t ON t.id = tp.topic_id WHERE tp.user_id = ? ORDER BY t.position'),
            'sessions' => $q('SELECT id, mode, purpose, status, local_date, started_at, completed_at FROM learning_sessions WHERE user_id = ? ORDER BY started_at'),
            'drafts' => $json($q('SELECT session_id, revision, step_key, payload, updated_at FROM session_drafts WHERE user_id = ?'), ['payload']),
            'attempts' => $json($q('SELECT a.id, a.session_id, cv.content_key AS item, cv.version_major || \'.\' || cv.version_minor || \'.\' || cv.version_patch AS item_version, a.step_key, a.attempt_no, a.purpose, a.origin, a.response, a.outcome, a.evidence_basis, a.assistance, a.hint_count, a.submitted_at, a.feedback_viewed_at FROM attempts a JOIN content_versions cv ON cv.id = a.content_version_id WHERE a.user_id = ? ORDER BY a.submitted_at'), ['response']),
            'assistance' => $q('SELECT session_id, step_key, kind, hint_index, used_at FROM session_assistance WHERE user_id = ? ORDER BY used_at'),
            'skill_evidence' => $json($q('SELECT s.skill_key, se.level, se.basis, se.assistance, se.source, se.limitations, se.observed_at FROM skill_evidence se JOIN skills s ON s.id = se.skill_id WHERE se.user_id = ? AND se.voided_at IS NULL ORDER BY se.observed_at'), ['limitations']),
            'review_schedule' => $q('SELECT s.skill_key, r.status, r.interval_step, r.due_on, r.demonstrated_at, r.retained_at FROM review_schedule r JOIN skills s ON s.id = r.skill_id WHERE r.user_id = ?'),
        ];
        $readme = 'DevStep data export (format version '.self::FORMAT_VERSION.")\n\n"
            ."account.json  - your account, preferences and goals\nlearning.json - enrolments, progress, sessions, answers, evidence and review schedule\n\n"
            ."Not included: your password hash, one-time tokens, and DevStep's authored lesson content and answer keys (your answers refer to items by key and version).\n"
            ."Your answers are your own text; nothing here is sent to analytics.\n";

        $path = tempnam(sys_get_temp_dir(), 'devstep-export');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $zip->addFromString('README.txt', $readme);
        $zip->addFromString('account.json', json_encode($account, $flags));
        $zip->addFromString('learning.json', json_encode($learning, $flags));
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }
}
