<?php

namespace App\Modules\Learning;

use App\Modules\Analytics\EventRecorder;
use App\Modules\Catalogue\ContentRepository;
use App\Support\ApiException;
use App\Support\Clock;
use App\Support\Ids;
use App\Support\Learner;
use Illuminate\Support\Facades\DB;

/**
 * Hints, worked examples and reveals are recorded BEFORE they are shown, so a reload cannot turn assisted
 * work into unassisted work (docs/04 session_assistance, F04). Tiers unlock in order; "Show solution" is
 * always available behind a confirmation the client enforces.
 */
final class AssistanceService
{
    public function __construct(private SessionService $sessions, private ContentRepository $content) {}

    /** @return array{hint_number:int, kind:string, text:string, remaining:int} */
    public function hint(Learner $learner, string $sessionId, int $stepIndex, int $hintNumber): array
    {
        return DB::transaction(function () use ($learner, $sessionId, $stepIndex, $hintNumber) {
            [$session, $step, $item] = $this->locate($learner, $sessionId, $stepIndex);
            $hints = $item->body['hints'] ?? [];
            if ($hintNumber < 1 || $hintNumber > count($hints)) {
                throw new ApiException('hint_unavailable', 'There is no such hint.', 404);
            }
            // After a submission, hints are just reading: nothing is recorded and nothing changes (AT-1).
            if (DB::selectOne('SELECT 1 FROM attempts WHERE session_id = ? AND step_key = ?', [$sessionId, $step['key']])) {
                return ['hint_number' => $hintNumber, 'kind' => 'hint', 'text' => $hints[$hintNumber - 1], 'remaining' => count($hints) - $hintNumber];
            }
            $used = (int) DB::scalar("SELECT count(*) FROM session_assistance WHERE session_id = ? AND step_key = ? AND kind = 'hint'", [$sessionId, $step['key']]);
            if ($hintNumber > $used + 1) {
                throw new ApiException('hint_locked', 'Hints open in order. Try the earlier hint first.', 409);
            }
            $inserted = DB::insert(
                "INSERT INTO session_assistance (id, user_id, session_id, step_key, content_version_id, kind, hint_index, used_at) VALUES (?, ?, ?, ?, ?, 'hint', ?, ?)
                 ON CONFLICT (session_id, step_key, kind, hint_index) DO NOTHING",
                [Ids::new(), $learner->id, $sessionId, $step['key'], $item->id, $hintNumber, Clock::now()],
            );
            DB::afterCommit(fn () => EventRecorder::server('hint_used', $learner->subjectId, [
                'assessment_item_id' => $item->id, 'hint_index' => $hintNumber, 'hint_kind' => 'hint',
            ], ['session_ref' => $sessionId, 'content_version_id' => $item->id, 'mode' => $session->mode], $sessionId.'|'.$step['key'].'|hint|'.$hintNumber));

            return ['hint_number' => $hintNumber, 'kind' => 'hint', 'text' => $hints[$hintNumber - 1], 'remaining' => count($hints) - $hintNumber];
        });
    }

    /**
     * Show the solution. Before an answer is submitted it records `solution_revealed` (no qualifying attempt,
     * nothing above `introduced`, a fresh attempt is scheduled). After submission the model answer is feedback,
     * not a reveal, and nothing is recorded.
     *
     * @return array{text:string, recorded:bool}
     */
    public function reveal(Learner $learner, string $sessionId, int $stepIndex): array
    {
        return DB::transaction(function () use ($learner, $sessionId, $stepIndex) {
            [$session, $step, $item] = $this->locate($learner, $sessionId, $stepIndex);
            $answered = DB::selectOne('SELECT 1 FROM attempts WHERE session_id = ? AND step_key = ?', [$sessionId, $step['key']]) !== null;
            if (! $answered) {
                DB::insert(
                    "INSERT INTO session_assistance (id, user_id, session_id, step_key, content_version_id, kind, hint_index, used_at) VALUES (?, ?, ?, ?, ?, 'solution_revealed', 0, ?)
                     ON CONFLICT (session_id, step_key, kind, hint_index) DO NOTHING",
                    [Ids::new(), $learner->id, $sessionId, $step['key'], $item->id, Clock::now()],
                );
                $hintsBefore = (int) DB::scalar("SELECT count(*) FROM session_assistance WHERE session_id = ? AND step_key = ? AND kind = 'hint'", [$sessionId, $step['key']]);
                DB::afterCommit(fn () => EventRecorder::server('answer_revealed', $learner->subjectId, [
                    'assessment_item_id' => $item->id, 'hints_before' => min($hintsBefore, 10), 'after_attempt' => false,
                ], ['session_ref' => $sessionId, 'content_version_id' => $item->id, 'mode' => $session->mode], $sessionId.'|'.$step['key'].'|reveal'));
            }

            return ['text' => $item->body['reveal'], 'recorded' => ! $answered];
        });
    }

    /** @return array{0: object, 1: array<string, mixed>, 2: object} */
    private function locate(Learner $learner, string $sessionId, int $stepIndex): array
    {
        $session = $this->sessions->find($learner, $sessionId);
        if ($session->status !== 'open') {
            throw ApiException::conflict('session_not_open', 'Open this session again to continue.');
        }
        $plan = json_decode($session->plan, true);
        $step = $plan['steps'][$stepIndex] ?? null;
        if (! $step || empty($step['item_cv'])) {
            throw new ApiException('step_invalid', 'That step has no question.', 422);
        }
        if (in_array($step['purpose'], ['challenge', 'diagnostic', 'transfer'], true)) {
            throw new ApiException('assistance_disabled', 'Hints are switched off for this activity.', 409);
        }

        return [$session, $step, $this->content->itemMeta($step['item_cv'])];
    }
}
