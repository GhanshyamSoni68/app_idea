<?php

namespace App\Modules\Learning;

use App\Modules\Analytics\EventRecorder;
use App\Modules\Profile\ProfileService;
use App\Modules\Roadmap\RoadmapService;
use App\Support\ApiException;
use App\Support\Clock;
use App\Support\Learner;
use App\Support\LearnerDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Completes a session exactly once (docs/04 §5.3). A conditional UPDATE decides which request wins; a
 * repeat with the same key replays the stored response. Topic completion, evidence and the review ladder
 * are applied through the feedback acknowledger inside the same transaction; analytics go out after commit.
 */
final class CompletionService
{
    public function __construct(
        private SessionService $sessions,
        private AttemptService $attempts,
        private RoadmapService $roadmap,
        private ProfileService $profile,
        private ChallengeService $challenge,
    ) {}

    /** @return array{0:int, 1:array<string, mixed>} */
    public function complete(Learner $learner, string $sessionId, int $baseRevision): array
    {
        $session = DB::selectOne('SELECT * FROM learning_sessions WHERE id = ? AND user_id = ? FOR UPDATE', [$sessionId, $learner->id]);
        if (! $session) {
            throw ApiException::notFound('That session');
        }
        $enrolment = $session->enrolment_id ? DB::selectOne('SELECT * FROM roadmap_enrolments WHERE id = ?', [$session->enrolment_id]) : null;

        if ($session->status === 'completed') {
            // Completed earlier by another key or device: current state, no side effects.
            return [200, $this->summary($learner, $session, $enrolment, ['evidence' => [], 'topics' => []], alreadyCompleted: true)];
        }
        if (! in_array($session->status, ['open', 'suspended'], true)) {
            throw ApiException::conflict('session_not_open', 'This session has been set aside and cannot be completed.');
        }

        $draft = DB::selectOne('SELECT revision, payload, updated_at, device_label FROM session_drafts WHERE session_id = ?', [$sessionId]);
        if ($draft && (int) $draft->revision > $baseRevision) {
            throw new ApiException('draft_conflict', 'This draft was changed on another device. Sync it before finishing.', 409, false, [
                'server' => ['revision' => (int) $draft->revision, 'data' => json_decode($draft->payload, true), 'saved_at' => $draft->updated_at, 'device_label' => $draft->device_label],
            ]);
        }

        $now = Clock::now();
        $won = DB::selectOne(
            "UPDATE learning_sessions SET status = 'completed', completed_at = ?, last_activity_at = ? WHERE id = ? AND user_id = ? AND status IN ('open', 'suspended') RETURNING *",
            [$now, $now, $sessionId, $learner->id],
        );
        if (! $won) {
            return [200, $this->summary($learner, $session, $enrolment, ['evidence' => [], 'topics' => []], alreadyCompleted: true)];
        }

        $plan = json_decode($won->plan, true);
        $changes = $this->attempts->acknowledgeThrough($learner, $won, count($plan['steps']));
        $verdict = ($won->purpose === 'challenge' && $enrolment) ? $this->challenge->finalise($learner, $won, $enrolment) : null;
        $summary = $this->summary($learner, $won, $enrolment, $changes, challenge: $verdict);

        $metCount = (int) DB::scalar("SELECT count(*) FROM attempts WHERE session_id = ? AND outcome IN ('correct', 'self_met') AND attempt_no = 1", [$sessionId]);
        $attemptCount = (int) DB::scalar('SELECT count(*) FROM attempts WHERE session_id = ? AND attempt_no = 1', [$sessionId]);
        $reviewItems = collect($plan['steps'])->where('kind', 'response')->whereIn('purpose', ['retrieval', 'topic_check'])->count();
        $allAnswered = $attemptCount >= collect($plan['steps'])->where('kind', 'response')->count();
        DB::afterCommit(fn () => EventRecorder::server('session_completed', $learner->subjectId, [
            'items_attempted' => min($attemptCount, 100), 'items_met' => min($metCount, 100), 'review_items_served' => min($reviewItems, 10),
            'duration_s' => (int) min(604800, CarbonImmutable::parse($won->started_at)->diffInSeconds($now)),
            'completion_reason' => $allAnswered ? 'finished' : 'stopped_at_point',
        ], ['session_ref' => $sessionId, 'mode' => $won->mode, 'local_date' => $learner->day(), 'content_version_id' => $won->content_version_id], $sessionId));

        return [200, $summary];
    }

    /** @return array<string, mixed> */
    private function summary(Learner $learner, object $session, ?object $enrolment, array $changes, bool $alreadyCompleted = false, ?array $challenge = null): array
    {
        $progress = $enrolment ? $this->roadmap->progress($enrolment) : null;
        // Read what this session earned from the database, so evidence recorded when the learner moved past
        // a feedback step is included and a repeat call returns the same summary.
        $evidence = array_map(fn ($r) => [
            'skill_id' => $r->skill_id, 'skill_key' => $r->skill_key, 'title' => $r->title, 'level' => $r->level, 'basis' => $r->basis, 'assistance' => $r->assistance,
        ], DB::select(
            'SELECT se.skill_id, s.skill_key, s.title, se.level, se.basis, se.assistance FROM skill_evidence se
             JOIN attempts a ON a.id = se.attempt_id JOIN skills s ON s.id = se.skill_id WHERE a.session_id = ? ORDER BY se.observed_at',
            [$session->id],
        ));
        $topicChanges = array_map(fn ($r) => ['topic_id' => $r->topic_id, 'topic_key' => $r->topic_key, 'title' => $r->title, 'from_state' => 'in_progress', 'to_state' => 'completed'], DB::select(
            "SELECT tp.topic_id, t.topic_key, t.title FROM topic_progress tp JOIN roadmap_topics t ON t.id = tp.topic_id
             JOIN attempts a ON a.id = tp.completion_attempt_id WHERE a.session_id = ? AND tp.state = 'completed'",
            [$session->id],
        ));

        $plan = json_decode($session->plan, true);
        $highlights = ['started'];
        $milestone = $enrolment && $enrolment->completed_at ? ['completed_at' => $enrolment->completed_at, 'note' => 'This shows the topics were covered and understanding was checked, not verified implementation proficiency.'] : null;

        return [
            'session_id' => $session->id,
            'completed_at' => $session->completed_at,
            'already_completed' => $alreadyCompleted ?: null,
            'summary' => [
                'steps_done' => count($plan['steps']),
                'attempts' => (int) DB::scalar('SELECT count(*) FROM attempts WHERE session_id = ? AND attempt_no = 1', [$session->id]),
                'evidence_changes' => $evidence,
                'effort_highlights' => $highlights,
            ],
            'topic_changes' => $topicChanges,
            'challenge' => $challenge ?? ($session->purpose === 'challenge' ? ['passed' => (bool) count($topicChanges)] : null),
            'roadmap_progress' => $progress,
            'milestone' => $milestone,
            'next_suggestion' => $this->nextDay($learner),
        ];
    }

    /** The next planned learning day, offered as a suggestion rather than a demand. */
    private function nextDay(Learner $learner): ?array
    {
        $prefs = $this->profile->preferences($learner->id);
        if (! $prefs || ! $prefs['available_days']) {
            return null;
        }
        $day = $learner->day();
        for ($i = 1; $i <= 7; $i++) {
            $candidate = LearnerDay::addDays($day, $i);
            if (in_array((int) CarbonImmutable::parse($candidate)->isoWeekday(), $prefs['available_days'], true)) {
                return ['local_date' => $candidate, 'weekday' => CarbonImmutable::parse($candidate)->format('l')];
            }
        }

        return null;
    }
}
