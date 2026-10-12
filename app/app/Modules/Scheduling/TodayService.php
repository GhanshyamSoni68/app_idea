<?php

namespace App\Modules\Scheduling;

use App\Modules\Catalogue\ContentRepository;
use App\Modules\Profile\ProfileService;
use App\Modules\Roadmap\RoadmapService;
use App\Support\Clock;
use App\Support\Learner;
use App\Support\LearnerDay;
use Illuminate\Support\Facades\DB;

/**
 * `scheduling` module: Today (docs/05 §3). A deterministic function of learner state, the learner's local
 * day and one parameter set. Exactly one primary action; the plan is recomputed on every request and again
 * when a session starts (TD-5). No backlog size, overdue count or red state is ever returned (RV-12).
 */
final class TodayService
{
    public function __construct(
        private ContentRepository $content,
        private RoadmapService $roadmap,
        private ReviewService $reviews,
        private ProfileService $profile,
    ) {}

    /** @return array<string, mixed> The GET /v1/today payload (docs/02 §5.3). */
    public function recommend(Learner $learner, ?string $requestedMode = null): array
    {
        $day = $learner->day();
        $prefs = $this->profile->preferences($learner->id);
        $mode = $requestedMode ?? ($prefs['preferred_mode'] ?? 'practise');
        if ($mode === 'build') {
            $mode = 'practise'; // labs arrive in a later epic; build mode plans fall back to practise
        }
        $enrolment = $this->roadmap->current($learner->id);

        $base = [
            'local_date' => $day,
            'time_zone' => $learner->timeZone,
            'reviews_included' => 0,
            'weekly_progress' => $this->weekly($learner, $day, (int) ($prefs['weekly_session_target'] ?? config('devstep.weekly_target_default'))),
            'enrolment' => null,
            'welcome_back' => null,
            'secondary_actions' => [],
            'smaller_option' => null,
        ];

        if (! $enrolment) {
            return $base + ['situation' => 'first_run', 'primary_action' => $this->simple('enrol', 'Start the roadmap', 'ENROL',
                'Pick up the From CRUD to Reliable Systems roadmap. You will see the topics and effort before you begin.', 'Not enrolled yet')];
        }

        $progress = $this->roadmap->progress($enrolment);
        $base['enrolment'] = [
            'id' => $enrolment->id,
            'roadmap_slug' => (string) DB::scalar('SELECT slug FROM roadmaps WHERE id = ?', [$enrolment->roadmap_id]),
            'status' => $enrolment->status,
            'completed_required' => $progress['completed_required'],
            'total_required' => $progress['total_required'],
            'migration_available' => false,
        ];

        if ($enrolment->status === 'paused') {
            return $base + ['situation' => 'paused', 'primary_action' => $this->simple('resume_enrolment', 'Resume your roadmap', 'PAUSED', 'Your roadmap is paused. Resume whenever you are ready.', 'Paused')];
        }

        $open = DB::selectOne("SELECT id FROM learning_sessions WHERE user_id = ? AND status = 'open'", [$learner->id]);
        $practisedToday = $this->practisedOn($learner, $day);
        if ($practisedToday && ! $open && $requestedMode === null) {
            $next = $this->plan($learner, $enrolment, $mode, $day);
            $base['smaller_option'] = null;

            return $base + [
                'situation' => 'done_for_today',
                'primary_action' => $this->simple('practise_anyway', 'Practise anyway', 'DONE_FOR_TODAY', 'Done for today. Practise anyway is optional.', 'Optional', $next['minutes'] ?? null),
            ];
        }

        $plan = $this->plan($learner, $enrolment, $mode, $day);
        $resume = $this->resumeCandidate($learner, $mode);
        $small = $mode === 'small' ? null : $this->plan($learner, $enrolment, 'small', $day, skipResume: true);

        if ($resume && ($plan === null || $plan['reason'] === 'RESUME')) {
            $plan = $this->resumePlan($resume);
        }
        if ($plan === null) {
            if ($progress['completed_required'] >= $progress['total_required'] && $progress['total_required'] > 0) {
                return $base + ['situation' => 'roadmap_complete', 'primary_action' => $this->simple('none', 'You have completed the roadmap', 'MAINTENANCE', 'Reviews will keep your skills fresh. This is optional.', 'Optional')];
            }

            return $base + ['situation' => 'nothing_ready', 'primary_action' => $this->simple('none', 'Nothing is due', 'NOTHING_DUE', 'You are up to date. This is optional; a rest day is fine too.', 'Optional')];
        }

        $base['reviews_included'] = $plan['reviews'];
        $base['smaller_option'] = $small ? $this->describe($small, 'small') : null;
        if ($resume && $plan['reason'] !== 'RESUME') {
            $base['secondary_actions'][] = $this->describe($this->resumePlan($resume), 'practise');
        }

        return $base + [
            'situation' => $plan['reason'] === 'RESUME' ? 'resume' : 'ready',
            'primary_action' => $this->describe($plan, $mode),
        ];
    }

    /**
     * The ordered plan behind the primary action (docs/05 §3.3), or null when nothing is ready.
     *
     * @return array<string, mixed>|null
     */
    public function plan(Learner $learner, ?object $enrolment, string $mode, ?string $day = null, bool $skipResume = false): ?array
    {
        $day ??= $learner->day();
        if (! $enrolment || $enrolment->status !== 'active') {
            return null;
        }
        if ($mode === 'build') {
            $mode = 'practise';
        }
        $cfg = config('devstep');
        $fit = (int) ceil($cfg['budget'][$mode] * (1 + $cfg['duration_tolerance']));

        if (! $skipResume && ($r = $this->resumeCandidate($learner, $mode))) {
            return $this->resumePlan($r);
        }

        $servedToday = 0;
        foreach (DB::select("SELECT submitted_at FROM attempts WHERE user_id = ? AND purpose IN ('retrieval', 'topic_check') AND origin = 'session' ORDER BY submitted_at DESC LIMIT 20", [$learner->id]) as $a) {
            $servedToday += $learner->dayOf($a->submitted_at) === $day ? 1 : 0;
        }
        $slots = min($cfg['review_cap'][$mode], $cfg['daily_review_cap'] - $servedToday);
        $reviewActivities = [];
        foreach ($this->reviews->due($learner->id, $day, $slots, $learner->timeZone) as $track) {
            $isCheck = $track->check_pending_topic_id !== null;
            $item = $this->reviews->choosePrompt($track, $day, $learner->timeZone, $isCheck);
            if (! $item) {
                continue;
            }
            $topic = $isCheck ? DB::selectOne('SELECT topic_key, title FROM roadmap_topics WHERE id = ?', [$track->check_pending_topic_id]) : null;
            $reviewActivities[] = [
                'kind' => 'review',
                'purpose' => $isCheck ? 'topic_check' : 'retrieval',
                'reason' => $isCheck ? 'TOPIC_CHECK' : 'REVIEW_DUE',
                'skill_id' => $track->skill_id,
                'review_id' => $track->id,
                'topic_id' => $track->check_pending_topic_id,
                'item_cv' => $item->id,
                'mission_key' => $item->mission_key,
                'minutes' => (float) ($item->body['time_estimate_minutes'] ?? $cfg['est_review_item_minutes']),
                'title' => $topic->title ?? $this->content->skillKey($track->skill_id),
                'days_since' => LearnerDay::diffDays(LearnerDay::of(\Carbon\CarbonImmutable::parse($track->updated_at), $learner->timeZone), $day),
            ];
        }

        if ($mode === 'small') {
            if ($reviewActivities) {
                return $this->build([$reviewActivities[0]], $mode, $enrolment, $learner);
            }
            foreach ($this->roadmap->readyTopics($enrolment) as $t) {
                if (! $t->mission_key || $this->smallVariantUsed($learner, $t->mission_key)) {
                    continue;
                }
                $mission = $this->content->latest('mission', $t->mission_key, (int) $t->mission_major);
                if ($mission && $a = $this->missionActivity($mission, $t, 'small')) {
                    return $this->build([$a], $mode, $enrolment, $learner);
                }
            }

            return null;
        }

        foreach ($this->roadmap->readyTopics($enrolment) as $t) {
            $mission = $t->mission_key ? $this->content->latest('mission', $t->mission_key, (int) $t->mission_major) : null;
            if (! $mission) {
                continue;
            }
            $act = $this->missionActivity($mission, $t, 'practise');
            if (! $act) {
                continue;
            }
            for ($n = count($reviewActivities); $n >= min(1, count($reviewActivities)); $n--) {
                $chosen = array_slice($reviewActivities, 0, $n);
                $minutes = array_sum(array_column($chosen, 'minutes')) + $act['minutes'];
                if ($minutes <= $fit) {
                    return $this->build(array_merge($chosen, [$act]), $mode, $enrolment, $learner);
                }
            }
            break;
        }
        if ($reviewActivities) {
            return $this->build($reviewActivities, $mode, $enrolment, $learner);
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function missionActivity(object $mission, object $topic, string $mode): ?array
    {
        $front = $mission->body['front'];
        $items = collect($this->content->missionItems($mission))->keyBy('content_key');
        $keys = $mode === 'small'
            ? ($front['small_variant']['steps'] ?? [])
            : array_values(array_map(fn ($s) => $s['key'], array_filter($front['steps'], fn ($s) => in_array('practise', $s['modes'] ?? ['practise'], true))));
        if (! $keys) {
            return null;
        }
        $byKey = collect($front['steps'])->keyBy('key');
        $minutes = 0.0;
        foreach ($keys as $k) {
            $step = $byKey[$k];
            $minutes += match ($step['kind']) {
                'response' => (float) ($items[$step['item']]->body['time_estimate_minutes'] ?? 1.5),
                'explanation' => 2.0,
                default => 1.0,
            };
        }

        return [
            'kind' => 'mission',
            'purpose' => $mode === 'small' ? 'small_variant' : 'in_mission',
            'reason' => $mode === 'small' ? 'SMALL_VARIANT' : 'NEXT_MISSION',
            'mission_cv' => $mission->id,
            'mission_key' => $mission->content_key,
            'topic_id' => $topic->id,
            'topic_key' => $topic->topic_key,
            'topic_title' => $topic->title,
            'module_title' => $topic->module_title,
            'module_position' => (int) $topic->module_position,
            'step_keys' => $keys,
            'minutes' => $minutes,
            'title' => $front['title'],
            'rationale' => $front['purpose'] ?? $front['title'],
        ];
    }

    /**
     * Freeze the ordered activities into session steps with the exact item versions (docs/04 learning_sessions.plan).
     *
     * @param  list<array<string, mixed>>  $activities
     * @return array<string, mixed>
     */
    private function build(array $activities, string $mode, object $enrolment, Learner $learner): array
    {
        $steps = [];
        $reviews = 0;
        foreach ($activities as $i => $a) {
            if ($a['kind'] === 'review') {
                $reviews++;
                $steps[] = ['key' => 'review-'.$i, 'kind' => 'response', 'activity' => $i, 'purpose' => $a['purpose'], 'item_cv' => $a['item_cv'], 'mission_cv' => null, 'stop_after' => true, 'title' => $a['purpose'] === 'topic_check' ? 'Short check' : 'Quick recall'];

                continue;
            }
            $mission = $this->content->version($a['mission_cv']);
            $items = collect($this->content->missionItems($mission))->keyBy('content_key');
            $byKey = collect($mission->body['front']['steps'])->keyBy('key');
            foreach ($a['step_keys'] as $k) {
                $s = $byKey[$k];
                $steps[] = [
                    'key' => $k, 'kind' => $s['kind'], 'activity' => $i, 'purpose' => $a['purpose'],
                    'item_cv' => $s['kind'] === 'response' ? $items[$s['item']]->id : null,
                    'mission_cv' => $mission->id, 'stop_after' => (bool) ($s['stop_after'] ?? false),
                ];
            }
        }
        foreach ($steps as $i => &$s) {
            $s['index'] = $i;
        }
        unset($s);

        $primary = collect($activities)->firstWhere('kind', 'mission') ?? $activities[0];
        $first = $activities[0];
        $reason = $primary['reason'];
        $module = $primary['module_title'] ?? null;

        return [
            'reason' => $first['kind'] === 'review' && $mode === 'small' ? $first['reason'] : $reason,
            'mode' => $mode,
            'minutes' => (int) ceil(array_sum(array_column($activities, 'minutes'))),
            'reviews' => $reviews,
            'activities' => $activities,
            'steps' => $steps,
            'topic_id' => $primary['topic_id'] ?? null,
            'content_version_id' => $primary['mission_cv'] ?? null,
            'context' => $this->context($enrolment, $primary),
            'purpose' => $primary['kind'] === 'review' ? 'review' : ($mode === 'small' ? 'roadmap' : 'roadmap'),
        ];
    }

    private function context(object $enrolment, array $primary): ?string
    {
        $title = (string) DB::scalar('SELECT title FROM roadmap_versions WHERE id = ?', [$enrolment->roadmap_version_id]);
        if (($primary['kind'] ?? '') === 'review') {
            $row = $primary['topic_id'] ? DB::selectOne('SELECT t.title, m.title AS mt, m.position FROM roadmap_topics t JOIN roadmap_modules m ON m.id = t.module_id WHERE t.id = ?', [$primary['topic_id']]) : null;

            return $row ? "{$title} · Module {$row->position}: {$row->mt} · {$row->title}" : $title;
        }

        return "{$title} · Module {$primary['module_position']}: {$primary['module_title']} · {$primary['topic_title']}";
    }

    /** @return array<string, mixed> */
    private function describe(array $plan, string $mode): array
    {
        $rationale = $this->rationale($plan);

        return [
            'kind' => $plan['reason'] === 'RESUME' ? 'resume' : (($plan['activities'][0]['kind'] ?? '') === 'review' && count($plan['activities']) === 1 ? 'review' : 'mission'),
            'reason_code' => $plan['reason'],
            'mode' => $plan['mode'] ?? $mode,
            'estimated_minutes' => $plan['minutes'],
            'title' => $this->title($plan),
            'rationale' => mb_strlen($rationale) > 120 ? mb_substr($rationale, 0, 117).'...' : $rationale,
            'context' => $plan['context'] ?? null,
            'session_id' => $plan['session_id'] ?? null,
            'recommendation_id' => $this->recommendationId($plan),
            'reviews_included' => $plan['reviews'] ?? 0,
        ];
    }

    private function title(array $plan): string
    {
        if ($plan['reason'] === 'RESUME') {
            return 'Resume: '.($plan['resume_title'] ?? 'your last task');
        }
        $acts = $plan['activities'];
        $mission = collect($acts)->firstWhere('kind', 'mission');
        $reviews = collect($acts)->where('kind', 'review')->count();
        $parts = [];
        if ($reviews) {
            $check = collect($acts)->where('purpose', 'topic_check')->count();
            $parts[] = $check ? ($reviews === 1 ? 'Short check' : "{$reviews} quick recalls") : ($reviews === 1 ? 'Quick recall' : "{$reviews} quick recalls");
        }
        if ($mission) {
            $parts[] = $mission['title'];
        }

        return implode(' + ', $parts);
    }

    private function rationale(array $plan): string
    {
        if ($plan['reason'] === 'RESUME') {
            return 'Pick up where you stopped: '.($plan['resume_title'] ?? 'your last task').'.';
        }
        $mission = collect($plan['activities'])->firstWhere('kind', 'mission');
        if ($mission) {
            return $mission['rationale'];
        }
        $first = $plan['activities'][0];
        if ($first['reason'] === 'TOPIC_CHECK') {
            return "A short check on a new scenario completes {$first['title']}.";
        }
        $days = max(1, (int) ($first['days_since'] ?? 1));

        return "Recalling {$first['title']} now, {$days} ".($days === 1 ? 'day' : 'days').' after you last practised it, helps it stick.';
    }

    /** Opaque, signed hash of the recommendation inputs; echoed to POST /v1/sessions (docs/02 §5.3). */
    private function recommendationId(array $plan): string
    {
        $inputs = json_encode([$plan['reason'], $plan['mode'] ?? '', array_map(fn ($a) => [$a['kind'], $a['item_cv'] ?? $a['mission_cv'] ?? null], $plan['activities'] ?? []), $plan['session_id'] ?? null]);

        return 'rec_'.substr(rtrim(strtr(base64_encode(hash_hmac('sha256', $inputs, (string) config('app.key'), true)), '+/', '-_'), '='), 0, 22);
    }

    /** @return array<string, mixed> */
    private function simple(string $kind, string $title, string $reason, string $rationale, string $context, ?int $minutes = null): array
    {
        return [
            'kind' => $kind, 'reason_code' => $reason, 'mode' => null, 'estimated_minutes' => $minutes, 'title' => $title,
            'rationale' => $rationale, 'context' => $context, 'session_id' => null, 'recommendation_id' => null, 'reviews_included' => 0,
        ];
    }

    /** TD-6: practised today = a meaningful activity (a qualifying attempt whose feedback was acknowledged). */
    private function practisedOn(Learner $learner, string $day): bool
    {
        $rows = DB::select('SELECT feedback_viewed_at FROM attempts WHERE user_id = ? AND feedback_viewed_at IS NOT NULL ORDER BY feedback_viewed_at DESC LIMIT 20', [$learner->id]);
        foreach ($rows as $r) {
            if ($learner->dayOf($r->feedback_viewed_at) === $day) {
                return true;
            }
        }

        return false;
    }

    private function smallVariantUsed(Learner $learner, string $missionKey): bool
    {
        return DB::selectOne(
            "SELECT 1 FROM learning_sessions ls JOIN content_versions cv ON cv.id = ls.content_version_id
             WHERE ls.user_id = ? AND ls.mode = 'small' AND ls.status = 'completed' AND cv.content_key = ?",
            [$learner->id, $missionKey],
        ) !== null;
    }

    /** @return array<string, mixed> */
    private function weekly(Learner $learner, string $day, int $target): array
    {
        $start = LearnerDay::weekStart($day);
        $rows = DB::select('SELECT feedback_viewed_at FROM attempts WHERE user_id = ? AND feedback_viewed_at IS NOT NULL AND feedback_viewed_at > ?::timestamptz - interval \'8 days\'', [$learner->id, Clock::now()]);
        $days = [];
        foreach ($rows as $r) {
            $d = $learner->dayOf($r->feedback_viewed_at);
            if ($d >= $start && $d <= LearnerDay::addDays($start, 6)) {
                $days[$d] = true;
            }
        }

        return ['practice_days_target' => $target, 'practice_days_done' => count($days), 'week_start' => $start];
    }

    private function resumeCandidate(Learner $learner, string $mode): ?object
    {
        $s = DB::selectOne(
            "SELECT * FROM learning_sessions WHERE user_id = ? AND status IN ('open', 'suspended') ORDER BY last_activity_at DESC LIMIT 1",
            [$learner->id],
        );
        if (! $s) {
            return null;
        }
        $plan = json_decode($s->plan, true);
        $fit = (int) ceil(config('devstep.budget')[$mode] * (1 + config('devstep.duration_tolerance')));
        if ($mode === 'small' && ($plan['minutes'] ?? 99) > $fit) {
            return null;
        }

        return $s;
    }

    /** @return array<string, mixed> */
    private function resumePlan(object $s): array
    {
        $plan = json_decode($s->plan, true);
        $plan['reason'] = 'RESUME';
        $plan['session_id'] = $s->id;
        $first = collect($plan['activities'])->firstWhere('kind', 'mission') ?? $plan['activities'][0];
        $plan['resume_title'] = $first['title'] ?? 'your last task';

        return $plan;
    }
}
