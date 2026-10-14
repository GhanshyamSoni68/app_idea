<?php

namespace App\Modules\Learning;

use App\Modules\Analytics\EventRecorder;
use App\Modules\Analytics\EventRegistry;
use App\Modules\Assessment\Evaluator;
use App\Modules\Catalogue\ContentRepository;
use App\Modules\Catalogue\LearnerProjection;
use App\Modules\Roadmap\RoadmapService;
use App\Modules\Scheduling\TodayService;
use App\Support\ApiException;
use App\Support\Clock;
use App\Support\Ids;
use App\Support\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `learning` module: sessions with a frozen plan of exact content versions (docs/04 learning_sessions).
 * One open session per learner, whatever its mode; starting another suspends it and keeps its draft.
 */
final class SessionService
{
    public function __construct(
        private ContentRepository $content,
        private TodayService $today,
        private RoadmapService $roadmap,
        private Evaluator $evaluator,
    ) {}

    /**
     * POST /v1/sessions. Returns [payload, created].
     *
     * @param  array{mode?:string, session_id?:string, if_open?:string, recommendation_id?:string, origin?:string}  $in
     * @return array{0: array<string, mixed>, 1: bool}
     */
    public function start(Learner $learner, array $in): array
    {
        return DB::transaction(function () use ($learner, $in) {
            $ifOpen = $in['if_open'] ?? 'resume';
            $open = DB::selectOne("SELECT * FROM learning_sessions WHERE user_id = ? AND status = 'open' FOR UPDATE", [$learner->id]);

            if (! empty($in['session_id'])) {
                $target = DB::selectOne('SELECT * FROM learning_sessions WHERE id = ? AND user_id = ? FOR UPDATE', [$in['session_id'], $learner->id]);
                if (! $target || ! in_array($target->status, ['open', 'suspended'], true)) {
                    throw ApiException::notFound('That session');
                }
                if ($target->status === 'suspended') {
                    if ($open) {
                        $this->suspend($open);
                    }
                    DB::update("UPDATE learning_sessions SET status = 'open', last_activity_at = ? WHERE id = ?", [Clock::now(), $target->id]);
                }
                $this->resumedEvent($learner, $target, $in['origin'] ?? 'today');

                return [$this->payload($learner, $target->id), false];
            }

            if ($open && $ifOpen === 'resume') {
                $this->resumedEvent($learner, $open, $in['origin'] ?? 'today');

                return [$this->payload($learner, $open->id), false];
            }

            $enrolment = $this->roadmap->current($learner->id);
            $mode = $in['mode'] ?? 'practise';
            $plan = (($in['origin'] ?? null) === 'return_screen' ? $this->today->refresherPlan($learner, $enrolment) : null)
                ?? $this->today->plan($learner, $enrolment, $mode, skipResume: true);
            if ($plan === null) {
                throw ApiException::conflict('nothing_to_start', 'There is nothing ready to start right now. That is fine: a rest day is part of the plan.');
            }
            if ($open) {
                $this->suspend($open);
            }

            $id = Ids::new();
            $now = Clock::now();
            DB::insert(
                'INSERT INTO learning_sessions (id, user_id, mode, purpose, status, enrolment_id, topic_id, content_version_id, plan, recommendation_id, local_date, started_at, last_activity_at)
                 VALUES (?, ?, ?, ?, \'open\', ?, ?, ?, ?::jsonb, ?, ?, ?, ?)',
                [$id, $learner->id, $plan['mode'], $plan['purpose'], $enrolment->id, $plan['topic_id'], $plan['content_version_id'], json_encode($plan),
                    $in['recommendation_id'] ?? null, $learner->day(), $now, $now],
            );
            $session = DB::selectOne('SELECT * FROM learning_sessions WHERE id = ?', [$id]);

            $topic = $plan['topic_id'] ? DB::selectOne('SELECT * FROM roadmap_topics WHERE id = ?', [$plan['topic_id']]) : null;
            if ($topic && $plan['mode'] !== 'small' && collect($plan['activities'])->contains(fn ($a) => $a['kind'] === 'mission')) {
                $this->roadmap->markStarted($enrolment, $topic, $learner->subjectId);
            }
            $origin = $in['origin'] ?? ($plan['mode'] === 'small' ? 'smaller_mode' : 'today');
            DB::afterCommit(fn () => EventRecorder::server('session_started', $learner->subjectId, [
                'origin' => $origin, 'has_recommendation' => ! empty($in['recommendation_id']),
                'roadmap_enrolment_id' => $enrolment->id, 'topic_id' => $plan['topic_id'],
            ], ['session_ref' => $id, 'content_version_id' => $plan['content_version_id'], 'mode' => $plan['mode'], 'local_date' => $learner->day(), 'roadmap_version_id' => $enrolment->roadmap_version_id], $id));

            return [$this->payload($learner, $id), true];
        });
    }

    private function suspend(object $session): void
    {
        DB::update("UPDATE learning_sessions SET status = 'suspended', last_activity_at = ? WHERE id = ?", [Clock::now(), $session->id]);
    }

    private function resumedEvent(Learner $learner, object $session, string $origin): void
    {
        $gap = (int) CarbonImmutable::parse($session->last_activity_at)->diffInMinutes(Clock::now());
        DB::afterCommit(fn () => EventRecorder::server('session_resumed', $learner->subjectId, [
            'gap_minutes' => min($gap, 43200), 'resume_origin' => in_array($origin, EventRegistry::ORIGINS, true) ? $origin : 'today',
        ], ['session_ref' => $session->id, 'mode' => $session->mode]));
    }

    public function find(Learner $learner, string $id): object
    {
        $s = DB::selectOne('SELECT * FROM learning_sessions WHERE id = ? AND user_id = ?', [$id, $learner->id]);
        if (! $s) {
            throw ApiException::notFound('That session');
        }

        return $s;
    }

    /** GET /v1/sessions/{id}: the session, its steps (answer keys stripped) and the latest draft. */
    public function payload(Learner $learner, string $id): array
    {
        $s = $this->find($learner, $id);
        $plan = json_decode($s->plan, true);
        $attempts = collect(DB::select('SELECT * FROM attempts WHERE session_id = ? AND user_id = ? ORDER BY attempt_no', [$id, $learner->id]))->groupBy('step_key');

        $steps = [];
        foreach ($plan['steps'] as $ps) {
            $steps[] = $this->projectStep($ps, $attempts[$ps['key']] ?? collect());
        }
        $draft = DB::selectOne('SELECT revision, step_key, payload, device_label, updated_at FROM session_drafts WHERE session_id = ?', [$id]);
        $answered = collect($plan['steps'])->filter(fn ($st) => isset($attempts[$st['key']]))->count();

        return [
            'session' => [
                'id' => $s->id, 'mode' => $s->mode, 'purpose' => $s->purpose, 'status' => $s->status,
                'started_at' => $s->started_at, 'estimated_minutes' => $plan['minutes'], 'context' => $plan['context'] ?? null,
                'current_step' => min($answered === 0 ? 0 : $this->firstOpenStep($plan, $attempts), count($plan['steps']) - 1),
                'content_version_id' => $s->content_version_id,
                // AI-made practice a person has not reviewed is labelled wherever it appears (docs/16 §7).
                'unreviewed_ai' => $s->content_version_id !== null && (bool) DB::selectOne(
                    "SELECT 1 FROM content_versions cv JOIN content_releases cr ON cr.id = cv.release_id WHERE cv.id = ? AND cr.origin = 'generated' AND cr.review_status = 'unreviewed'",
                    [$s->content_version_id],
                ),
            ],
            'steps' => $steps,
            'draft' => $draft ? ['revision' => (int) $draft->revision, 'step_key' => $draft->step_key, 'data' => json_decode($draft->payload, true), 'device_label' => $draft->device_label, 'saved_at' => $draft->updated_at] : null,
            'resumed' => false,
        ];
    }

    private function firstOpenStep(array $plan, $attempts): int
    {
        foreach ($plan['steps'] as $i => $st) {
            if ($st['kind'] === 'response' && ! isset($attempts[$st['key']])) {
                return $i;
            }
        }

        return count($plan['steps']) - 1;
    }

    private function projectStep(array $ps, $stepAttempts): array
    {
        $out = ['index' => $ps['index'], 'key' => $ps['key'], 'kind' => $ps['kind'], 'purpose' => $ps['purpose'], 'stop_after' => $ps['stop_after'], 'title' => $ps['title'] ?? null, 'body' => '', 'item' => null, 'attempt' => null,
            'content_version_id' => $ps['mission_cv'] ?? $ps['item_cv']];
        if ($ps['mission_cv']) {
            $mission = $this->content->version($ps['mission_cv']);
            $section = $mission->body['sections'][$ps['key']] ?? null;
            $out['title'] = $section['heading'] ?? ucfirst(str_replace('-', ' ', $ps['key']));
            $out['body'] = $section['body'] ?? '';
        }
        if ($ps['item_cv']) {
            $item = $this->content->itemMeta($ps['item_cv']);
            $out['item'] = LearnerProjection::item($item);
            if ($stepAttempts->isNotEmpty()) {
                $first = $stepAttempts->first();
                $mission = $this->content->missionForItem($item);
                $eval = $this->evaluator->evaluate($item->body, json_decode($first->response, true), $mission ? LearnerProjection::misconceptions($mission) : []);
                $out['attempt'] = [
                    'id' => $first->id, 'outcome' => $first->outcome, 'assistance' => $first->assistance, 'hint_count' => (int) $first->hint_count,
                    'feedback' => $eval['feedback'], 'feedback_viewed' => $first->feedback_viewed_at !== null, 'attempts' => $stepAttempts->count(),
                ];
            }
        }

        return $out;
    }
}
