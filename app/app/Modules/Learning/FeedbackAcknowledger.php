<?php

namespace App\Modules\Learning;

use App\Modules\Analytics\EventRecorder;
use App\Modules\Assessment\Evaluator;
use App\Modules\Assessment\EvidenceService;
use App\Modules\Catalogue\ContentRepository;
use App\Modules\Roadmap\RoadmapService;
use App\Modules\Scheduling\ReviewService;
use App\Support\Clock;
use App\Support\Learner;
use App\Support\LearnerDay;
use Illuminate\Support\Facades\DB;

/**
 * "Feedback acknowledged" (docs/05 §2) turns an evaluated attempt into effects: skill evidence, the
 * review ladder, and topic progress. It runs when the learner moves past a feedback step or completes
 * the session, never on a mere page view. All effects are idempotent per attempt.
 */
final class FeedbackAcknowledger
{
    public function __construct(
        private ContentRepository $content,
        private EvidenceService $evidence,
        private ReviewService $reviews,
        private RoadmapService $roadmap,
    ) {}

    /**
     * @param  list<object>  $attempts  Evaluated attempts whose feedback has just been seen
     * @return array{evidence: list<array{skill_id:string, from_level:?string, to_level:string, basis:string}>, topics: list<array{topic_id:string, from_state:string, to_state:string}>}
     */
    public function acknowledge(Learner $learner, array $attempts, ?object $enrolment, string $sessionMode): array
    {
        $changes = [];
        $topics = [];
        foreach ($attempts as $attempt) {
            $updated = DB::update('UPDATE attempts SET feedback_viewed_at = ? WHERE id = ? AND user_id = ? AND feedback_viewed_at IS NULL', [Clock::now(), $attempt->id, $learner->id]);
            if ($updated === 0) {
                continue; // already acknowledged: no second set of effects
            }
            $attempt = DB::selectOne('SELECT * FROM attempts WHERE id = ?', [$attempt->id]);
            $item = $this->content->itemMeta($attempt->content_version_id);
            $mission = $this->missionOf($item);
            $teaching = $mission->body['front']['scenario_key'];
            $day = $learner->dayOf($attempt->submitted_at);

            // A challenge is judged as a whole when the session completes (both items), so its evidence waits.
            $change = $attempt->purpose === 'challenge' ? null : $this->evidence->recordForAttempt($attempt, $item, $teaching, $learner->timeZone);
            if ($change) {
                $changes[] = $change;
            }
            $topicChange = $this->applyEffects($learner, $attempt, $item, $mission, $change, $enrolment, $sessionMode, $day);
            if ($topicChange) {
                $topics[] = $topicChange;
            }
        }

        return ['evidence' => $changes, 'topics' => $topics];
    }

    private function missionOf(object $item): object
    {
        $row = DB::selectOne(
            "SELECT cv.* FROM content_versions cv, content_versions i WHERE i.id = ? AND cv.content_kind = 'mission' AND cv.content_key = ?
             AND cv.version_major = i.version_major AND cv.version_minor = i.version_minor AND cv.version_patch = i.version_patch",
            [$item->id, $item->mission_key],
        );
        $row->body = json_decode($row->body, true);

        return $row;
    }

    private function applyEffects(Learner $learner, object $attempt, object $item, object $mission, ?array $change, ?object $enrolment, string $sessionMode, string $day): ?array
    {
        $revealed = $attempt->assistance === 'solution_revealed';
        $met = Evaluator::isMet((string) $attempt->outcome);
        if ($attempt->purpose === 'challenge') {
            return null;
        }
        $topic = $enrolment ? $this->roadmap->topicByMission($enrolment, $item->mission_key) : null;

        if (in_array($attempt->purpose, ['in_mission', 'small_variant'], true)) {
            $track = $this->reviews->ensureTrack($learner->id, $item, $day, $enrolment->id ?? null);
            if (! $met || $revealed) {
                $this->reviews->onInMissionNotMet($track, $day);
            }
            // C1 + C2: a qualifying attempt in a full mission (not a small variant, SM-4) with feedback seen.
            if ($attempt->purpose === 'in_mission' && ! $revealed && (int) $attempt->attempt_no === 1 && $topic && $enrolment) {
                $this->roadmap->markStarted($enrolment, $topic, $learner->subjectId);
                if ($this->roadmap->markMissionActivityDone($enrolment, $topic, $day)) {
                    $this->reviews->onMissionActivityDone($learner->id, $item, $topic->id, $day, $enrolment->id);
                }
            }

            return null;
        }

        if (in_array($attempt->purpose, ['retrieval', 'topic_check'], true)) {
            $track = DB::selectOne('SELECT * FROM review_schedule WHERE user_id = ? AND objective_key = ? AND objective_major = ?', [$learner->id, $item->objective_key, $item->objective_major]);
            if (! $track) {
                return null;
            }
            $isCheck = $attempt->purpose === 'topic_check';
            $band = ReviewService::band($attempt->assistance, (int) $attempt->hint_count);
            $early = LearnerDay::diffDays($track->due_on, $day) < 0;
            $result = $this->reviews->applyRetrieval($track, (string) $attempt->outcome, $band, $isCheck, $day, $attempt->content_version_id, $attempt->id, $early);
            if ($change && in_array($change['to_level'], ['demonstrated', 'retained'], true)) {
                $this->reviews->markDemonstrated($track, (string) $attempt->submitted_at, $change['to_level'] === 'retained');
            }
            DB::afterCommit(fn () => EventRecorder::server('review_completed', $learner->subjectId, [
                'skill_id' => $item->skill_id, 'assessment_item_id' => $attempt->content_version_id,
                'interval_step' => (int) $track->interval_step, 'outcome' => $attempt->outcome, 'assistance' => $attempt->assistance,
                'next_interval_days' => $result['next_interval_days'], 'is_delayed_check' => (bool) $item->retention_reserved,
            ], ['session_ref' => $attempt->session_id, 'content_version_id' => $attempt->content_version_id], $attempt->id));

            if ($isCheck && $topic && $enrolment) {
                return $this->completeByCheck($learner, $enrolment, $topic, $attempt, $met, $band, $day);
            }
        }

        return null;
    }

    /** TC-1..TC-5: a check met with at most one hint, no reveal, on a later learner day than the mission attempt. */
    private function completeByCheck(Learner $learner, object $enrolment, object $topic, object $attempt, bool $met, string $band, string $day): ?array
    {
        if (! $met || $band === 'revealed' || $band === 'heavy') {
            return null;
        }
        if ((int) $attempt->hint_count > (int) config('devstep.topic_check_max_hints') || $attempt->assistance === 'worked_example') {
            return null;
        }
        $progress = DB::selectOne('SELECT mission_activity_done_on FROM topic_progress WHERE enrolment_id = ? AND topic_id = ?', [$enrolment->id, $topic->id]);
        if (! $progress || $progress->mission_activity_done_on === null) {
            return null;
        }
        $earliest = LearnerDay::addDays($progress->mission_activity_done_on, (int) config('devstep.topic_check_delay_days'));
        if (LearnerDay::diffDays($earliest, $day) < 0) {
            return null; // the check is served on a later learner day than the mission attempt
        }

        return $this->roadmap->completeTopic($enrolment, $topic, 'standard', $attempt->id, $learner->subjectId);
    }
}
