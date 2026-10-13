<?php

namespace App\Modules\Learning;

use App\Modules\Analytics\EventRecorder;
use App\Modules\Assessment\Evaluator;
use App\Modules\Assessment\EvidenceService;
use App\Modules\Catalogue\ContentRepository;
use App\Modules\Roadmap\RoadmapService;
use App\Modules\Scheduling\ReviewService;
use App\Support\ApiException;
use App\Support\Clock;
use App\Support\Ids;
use App\Support\Learner;
use Illuminate\Support\Facades\DB;

/**
 * Challenge-out (docs/05 §7.4, R03). One attempt per topic per enrolment. It uses the topic's two check-eligible
 * items in one sitting, with hints and reveal disabled. A pass completes the topic and demonstrates the skill; a
 * fail changes nothing about the topic and earns `practised`, with neutral copy ("Good to know where to start").
 */
final class ChallengeService
{
    public function __construct(
        private ContentRepository $content,
        private RoadmapService $roadmap,
        private SessionService $sessions,
        private EvidenceService $evidence,
        private ReviewService $reviews,
    ) {}

    /** @return list<object> the topic's check-eligible items: topic check item first, then Alternate A */
    private function items(object $topic, object $enrolment): array
    {
        $activity = DB::selectOne("SELECT ref_key, ref_major FROM topic_activities WHERE topic_id = ? AND role = 'core'", [$topic->id]);
        $mission = $activity ? $this->content->latest('mission', $activity->ref_key, (int) $activity->ref_major) : null;
        if (! $mission) {
            return [];
        }
        $items = array_values(array_filter($this->content->missionItems($mission), fn ($i) => $i->check_eligible));
        usort($items, fn ($a, $b) => [$a->role === 'topic_check' ? 0 : 1, $a->content_key] <=> [$b->role === 'topic_check' ? 0 : 1, $b->content_key]);

        return count($items) >= 2 ? $items : [];
    }

    /** CH-1 and CH-5: not completed, attempt unused, and no check-eligible item has been served yet. */
    public function availability(Learner $learner, object $enrolment, object $topic): bool
    {
        if ($topic->state === 'completed' || $topic->kind !== 'required') {
            return false;
        }
        $items = $this->items($topic, $enrolment);
        if (! $items) {
            return false;
        }
        $used = DB::selectOne("SELECT 1 FROM learning_sessions WHERE user_id = ? AND enrolment_id = ? AND topic_id = ? AND purpose = 'challenge'", [$learner->id, $enrolment->id, $topic->id]);
        if ($used) {
            return false;
        }
        $ids = array_column($items, 'id');
        $served = DB::selectOne('SELECT 1 FROM attempts WHERE user_id = ? AND content_version_id IN ('.implode(',', array_fill(0, count($ids), '?')).')', array_merge([$learner->id], $ids));

        return $served === null;
    }

    /** @return array<string, mixed> the new session payload */
    public function start(Learner $learner, string $topicId): array
    {
        return DB::transaction(function () use ($learner, $topicId) {
            [$enrolment, $topic] = $this->roadmap->ownTopic($learner->id, $topicId);
            if ($enrolment->status !== 'active') {
                throw ApiException::conflict('enrolment_paused', 'Resume your roadmap before taking a challenge.');
            }
            if (! $this->availability($learner, $enrolment, $topic)) {
                throw ApiException::conflict('challenge_unavailable', 'The challenge for this topic is not available. The short check after the mission completes the topic.');
            }
            $items = $this->items($topic, $enrolment);
            $open = DB::selectOne("SELECT id FROM learning_sessions WHERE user_id = ? AND status = 'open' FOR UPDATE", [$learner->id]);
            if ($open) {
                DB::update("UPDATE learning_sessions SET status = 'suspended', last_activity_at = ? WHERE id = ?", [Clock::now(), $open->id]);
            }

            $steps = [];
            foreach ($items as $i => $item) {
                $steps[] = ['index' => $i, 'key' => 'challenge-'.($i + 1), 'kind' => 'response', 'activity' => 0, 'purpose' => 'challenge', 'item_cv' => $item->id, 'mission_cv' => null, 'stop_after' => false, 'title' => 'Challenge, question '.($i + 1).' of '.count($items)];
            }
            $title = (string) DB::scalar('SELECT title FROM roadmap_versions WHERE id = ?', [$enrolment->roadmap_version_id]);
            $module = DB::selectOne('SELECT m.title, m.position FROM roadmap_modules m WHERE m.id = ?', [$topic->module_id]);
            $plan = [
                'reason' => 'CHALLENGE_SUGGESTED', 'mode' => 'practise', 'minutes' => 4, 'reviews' => 0,
                'activities' => [['kind' => 'challenge', 'title' => 'Challenge: '.$topic->title, 'minutes' => 4, 'topic_id' => $topic->id]],
                'steps' => $steps, 'topic_id' => $topic->id, 'content_version_id' => null, 'purpose' => 'challenge',
                'context' => "{$title} · Module {$module->position}: {$module->title} · {$topic->title}",
            ];
            $id = Ids::new();
            $now = Clock::now();
            DB::insert(
                "INSERT INTO learning_sessions (id, user_id, mode, purpose, status, enrolment_id, topic_id, content_version_id, plan, local_date, started_at, last_activity_at)
                 VALUES (?, ?, 'practise', 'challenge', 'open', ?, ?, NULL, ?::jsonb, ?, ?, ?)",
                [$id, $learner->id, $enrolment->id, $topic->id, json_encode($plan), $learner->day(), $now, $now],
            );
            DB::afterCommit(fn () => EventRecorder::server('session_started', $learner->subjectId, [
                'origin' => 'roadmap', 'has_recommendation' => false, 'roadmap_enrolment_id' => $enrolment->id, 'topic_id' => $topic->id,
            ], ['session_ref' => $id, 'mode' => 'practise', 'local_date' => $learner->day(), 'roadmap_version_id' => $enrolment->roadmap_version_id], $id));

            return $this->sessions->payload($learner, $id);
        });
    }

    /**
     * Judge the challenge when its session completes. Pass: both items met with no help. Returns the verdict.
     *
     * @return array{passed: bool}
     */
    public function finalise(Learner $learner, object $session, object $enrolment): array
    {
        $plan = json_decode($session->plan, true);
        $needed = count($plan['steps']);
        $attempts = DB::select('SELECT * FROM attempts WHERE session_id = ? AND user_id = ? AND attempt_no = 1 ORDER BY submitted_at', [$session->id, $learner->id]);
        $passed = count($attempts) === $needed && collect($attempts)->every(fn ($a) => Evaluator::isMet((string) $a->outcome) && $a->assistance === 'none');
        $topic = DB::selectOne('SELECT t.*, tp.state FROM roadmap_topics t JOIN topic_progress tp ON tp.topic_id = t.id AND tp.enrolment_id = ? WHERE t.id = ?', [$enrolment->id, $session->topic_id]);

        $skillDemonstrated = false;
        foreach ($attempts as $attempt) {
            $item = $this->content->itemMeta($attempt->content_version_id);
            $mission = $this->content->missionForItem($item);
            $change = $this->evidence->recordForAttempt($attempt, $item, $mission->body['front']['scenario_key'], $learner->timeZone, $passed ? null : 'practised');
            $skillDemonstrated = $skillDemonstrated || ($change && $change['to_level'] === 'demonstrated');
            $itemRow = $item;
        }

        if ($passed && $topic && isset($itemRow)) {
            $day = $learner->day();
            $last = end($attempts);
            $this->roadmap->completeTopic($enrolment, $topic, 'challenge_out', $last->id, $learner->subjectId, 'challenge_out');
            $this->roadmap->markMissionActivityDone($enrolment, $topic, $day);
            $track = $this->reviews->ensureTrack($learner->id, $itemRow, $day, $enrolment->id);
            // CH-3: the next check falls 7 days later on Alternate B, so it can earn `retained`.
            $step = max((int) $track->interval_step, (int) config('devstep.challenge_pass_rung'));
            DB::update('UPDATE review_schedule SET interval_step = ?, due_on = ?::date + ?::int, check_pending_topic_id = NULL, updated_at = now() WHERE id = ?', [$step, $day, ReviewService::interval($step), $track->id]);
            $this->reviews->markDemonstrated($track, (string) $last->submitted_at, false);
        }

        return ['passed' => $passed];
    }
}
