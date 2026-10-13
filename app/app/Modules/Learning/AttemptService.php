<?php

namespace App\Modules\Learning;

use App\Modules\Analytics\EventRecorder;
use App\Modules\Assessment\Evaluator;
use App\Modules\Catalogue\ContentRepository;
use App\Modules\Catalogue\LearnerProjection;
use App\Modules\Roadmap\RoadmapService;
use App\Support\ApiException;
use App\Support\Clock;
use App\Support\Ids;
use App\Support\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Submits and evaluates one attempt synchronously (docs/02 POST /sessions/{id}/attempts) and turns
 * acknowledged feedback into effects. The client cannot assert assistance: the server reads it from
 * session_assistance, which was written before any hint or reveal was shown.
 */
final class AttemptService
{
    public function __construct(
        private ContentRepository $content,
        private Evaluator $evaluator,
        private SessionService $sessions,
        private FeedbackAcknowledger $ack,
        private RoadmapService $roadmap,
    ) {}

    /**
     * Runs inside the Idempotency transaction. Returns [status, body].
     *
     * @return array{0:int, 1:array<string, mixed>}
     */
    public function submit(Learner $learner, string $sessionId, string $clientAttemptId, array $in): array
    {
        $session = DB::selectOne('SELECT * FROM learning_sessions WHERE id = ? AND user_id = ? FOR UPDATE', [$sessionId, $learner->id]);
        if (! $session) {
            throw ApiException::notFound('That session');
        }
        if ($session->status !== 'open') {
            throw ApiException::conflict('session_not_open', 'This session is not open. Open it again to continue.');
        }
        $plan = json_decode($session->plan, true);
        $step = $plan['steps'][(int) $in['step_index']] ?? null;
        if (! $step || empty($step['item_cv']) || $step['item_cv'] !== $in['assessment_item_id']) {
            throw new ApiException('step_invalid', 'That question does not belong to this step.', 422);
        }
        $pin = $step['mission_cv'] ?? $step['item_cv'];
        if ($in['content_version_id'] !== $pin) {
            throw ApiException::conflict('content_version_mismatch', 'This session was started on an earlier version of the content. Reload to continue.');
        }

        $item = $this->content->itemMeta($step['item_cv']);
        $mission = $this->content->missionForItem($item);
        $misconceptions = $mission ? LearnerProjection::misconceptions($mission) : [];
        $isRetry = (bool) ($in['retry'] ?? false);

        $existing = DB::select('SELECT * FROM attempts WHERE session_id = ? AND step_key = ? ORDER BY attempt_no', [$sessionId, $step['key']]);
        if ($existing && ! $isRetry) {
            // AT-3: a second submission for the same step (for example from another device) returns the first
            // evaluation with a notice. It is not a new attempt.
            $first = $existing[0];
            $eval = $this->evaluator->evaluate($item->body, json_decode($first->response, true), $misconceptions);

            return [200, $this->body($first, $eval, $step, $plan, notice: 'already_answered')];
        }

        $assistance = $this->assistance($sessionId, $step['key']);
        $eval = $this->evaluator->evaluate($item->body, $in['answer'], $misconceptions);
        $attemptNo = count($existing) + 1;
        $now = Clock::now();
        $id = Ids::new();
        DB::insert(
            'INSERT INTO attempts (id, user_id, session_id, content_version_id, step_key, attempt_no, client_attempt_id, purpose, origin, response, outcome, score, evaluated_at,
                evidence_basis, assistance, hint_count, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'session\', ?::jsonb, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $learner->id, $sessionId, $item->id, $step['key'], $attemptNo, $clientAttemptId, $step['purpose'], json_encode($in['answer']), $eval['outcome'], $eval['score'],
                $now, $eval['basis'], $assistance['assistance'], $assistance['hint_count'], $now],
        );
        DB::update('UPDATE learning_sessions SET last_activity_at = ? WHERE id = ?', [$now, $sessionId]);
        $attempt = DB::selectOne('SELECT * FROM attempts WHERE id = ?', [$id]);

        $firstInSession = ! DB::selectOne('SELECT 1 FROM attempts WHERE session_id = ? AND id <> ?', [$sessionId, $id]);
        $ctx = ['session_ref' => $sessionId, 'content_version_id' => $item->id, 'mode' => $session->mode, 'local_date' => $learner->day()];
        DB::afterCommit(function () use ($learner, $item, $eval, $attempt, $assistance, $ctx, $firstInSession, $session, $now, $step) {
            if ($firstInSession) {
                EventRecorder::server('first_answer_submitted', $learner->subjectId, [
                    'assessment_item_id' => $item->id,
                    'seconds_since_session_start' => (int) min(86400, CarbonImmutable::parse($session->started_at)->diffInSeconds($now)),
                ], $ctx, $attempt->session_id);
            }
            EventRecorder::server('attempt_evaluated', $learner->subjectId, [
                'attempt_id' => $attempt->id, 'assessment_item_id' => $item->id, 'skill_id' => $item->skill_id,
                'attempt_purpose' => match ($step['purpose']) {
                    'in_mission' => 'mission', 'small_variant' => 'small_variant', 'topic_check' => 'topic_check', 'challenge' => 'challenge_out', default => 'review'
                },
                'outcome' => $eval['outcome'], 'assistance' => $assistance['assistance'], 'hint_count' => $assistance['hint_count'],
                'evidence_basis' => $eval['basis'],
            ], $ctx, $attempt->id);
        });

        return [201, $this->body($attempt, $eval, $step, $plan)];
    }

    /** Assistance as the server recorded it before submission (docs/05 §2 bands). */
    private function assistance(string $sessionId, string $stepKey): array
    {
        $rows = DB::select('SELECT kind FROM session_assistance WHERE session_id = ? AND step_key = ?', [$sessionId, $stepKey]);
        $kinds = array_column($rows, 'kind');
        $hints = count(array_filter($kinds, fn ($k) => $k === 'hint'));
        $assistance = match (true) {
            in_array('solution_revealed', $kinds, true) => 'solution_revealed',
            in_array('worked_example', $kinds, true) => 'worked_example',
            $hints > 0 => 'hint',
            default => 'none',
        };

        return ['assistance' => $assistance, 'hint_count' => $hints];
    }

    /** @return array<string, mixed> */
    private function body(object $attempt, array $eval, array $step, array $plan, ?string $notice = null): array
    {
        $next = $step['index'] + 1;

        return array_filter([
            'attempt_id' => $attempt->id,
            'outcome' => $attempt->outcome,
            'assistance' => $attempt->assistance,
            'hint_count' => (int) $attempt->hint_count,
            'evidence_basis' => $attempt->evidence_basis,
            'feedback' => $eval['feedback'],
            // Evidence is recorded once the learner has engaged with this feedback (docs/05 §6.1), so it is
            // reported by the session summary, not here.
            'evidence_changes' => [],
            'next_step_index' => $next < count($plan['steps']) ? $next : null,
            'revisit_hint' => "We'll bring this back in a few days.",
            'notice' => $notice,
        ], fn ($v) => $v !== null);
    }

    /**
     * Mark feedback for every step before $stepIndex as reviewed and apply its effects once.
     * Returns the aggregated changes for the session summary.
     *
     * @return array{evidence: list<array<string, mixed>>, topics: list<array<string, mixed>>}
     */
    public function acknowledgeThrough(Learner $learner, object $session, int $stepIndex): array
    {
        $plan = json_decode($session->plan, true);
        $keys = array_column(array_slice($plan['steps'], 0, max(0, $stepIndex)), 'key');
        if (! $keys) {
            return ['evidence' => [], 'topics' => []];
        }
        $in = implode(',', array_fill(0, count($keys), '?'));
        $attempts = DB::select(
            "SELECT * FROM attempts WHERE session_id = ? AND user_id = ? AND feedback_viewed_at IS NULL AND outcome IS NOT NULL AND step_key IN ({$in}) ORDER BY submitted_at",
            array_merge([$session->id, $learner->id], $keys),
        );
        $enrolment = $session->enrolment_id ? DB::selectOne('SELECT * FROM roadmap_enrolments WHERE id = ?', [$session->enrolment_id]) : null;

        return $this->ack->acknowledge($learner, $attempts, $enrolment, $session->mode);
    }
}
