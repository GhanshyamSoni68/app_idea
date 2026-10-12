<?php

namespace App\Modules\Learning;

use App\Support\ApiException;
use App\Support\Clock;
use App\Support\Learner;
use Illuminate\Support\Facades\DB;

/**
 * Local-first drafts with revision compare-and-set (docs/04 §5.5, PRD §12). A stale save returns
 * 409 draft_conflict with the server copy; nothing is silently overwritten. Advancing past a feedback
 * step records "feedback reviewed".
 */
final class DraftService
{
    public function __construct(private SessionService $sessions, private AttemptService $attempts) {}

    /** @return array{revision:int, saved_at:string} */
    public function save(Learner $learner, string $sessionId, array $in): array
    {
        return DB::transaction(function () use ($learner, $sessionId, $in) {
            $session = $this->sessions->find($learner, $sessionId);
            if (! in_array($session->status, ['open', 'suspended'], true)) {
                throw ApiException::conflict('session_not_open', 'This session has finished.');
            }
            $payload = json_encode($in['data']);
            if (strlen($payload) > 65536) {
                throw new ApiException('draft_too_large', 'That draft is too large to save.', 422);
            }
            $base = (int) $in['base_revision'];
            $now = Clock::now();
            $stepKey = $this->stepKey($session, (int) $in['step_index']);

            if ($base === 0) {
                $row = DB::selectOne(
                    'INSERT INTO session_drafts (session_id, user_id, revision, step_key, payload, last_save_id, device_label, updated_at)
                     VALUES (?, ?, 1, ?, ?::jsonb, ?, ?, ?) ON CONFLICT (session_id) DO NOTHING RETURNING revision',
                    [$sessionId, $learner->id, $stepKey, $payload, $in['save_id'], $in['device_label'] ?? null, $now],
                );
            } else {
                $row = DB::selectOne(
                    'UPDATE session_drafts SET payload = ?::jsonb, step_key = ?, revision = revision + 1, last_save_id = ?, device_label = ?, updated_at = ?
                     WHERE session_id = ? AND user_id = ? AND revision = ? RETURNING revision',
                    [$payload, $stepKey, $in['save_id'], $in['device_label'] ?? null, $now, $sessionId, $learner->id, $base],
                );
            }

            if (! $row) {
                $server = DB::selectOne('SELECT revision, payload, device_label, updated_at, last_save_id FROM session_drafts WHERE session_id = ?', [$sessionId]);
                if ($server && $server->last_save_id === $in['save_id']) {
                    return ['revision' => (int) $server->revision, 'saved_at' => $server->updated_at]; // a retry, replayed
                }
                throw new ApiException('draft_conflict', 'This draft was changed on another device. Choose which version to keep.', 409, false, [
                    'server' => $server ? ['revision' => (int) $server->revision, 'data' => json_decode($server->payload, true), 'saved_at' => $server->updated_at, 'device_label' => $server->device_label] : null,
                ]);
            }

            DB::update('UPDATE learning_sessions SET last_activity_at = ? WHERE id = ?', [$now, $sessionId]);
            // Advancing to step N records feedback for every earlier step as reviewed.
            $this->attempts->acknowledgeThrough($learner, $session, (int) $in['step_index']);

            return ['revision' => (int) $row->revision, 'saved_at' => $now->toIso8601String()];
        });
    }

    private function stepKey(object $session, int $index): string
    {
        $plan = json_decode($session->plan, true);

        return $plan['steps'][$index]['key'] ?? throw new ApiException('step_invalid', 'That step does not exist.', 422);
    }
}
