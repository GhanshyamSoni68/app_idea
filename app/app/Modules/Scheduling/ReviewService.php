<?php

namespace App\Modules\Scheduling;

use App\Modules\Assessment\Evaluator;
use App\Modules\Catalogue\ContentRepository;
use App\Support\Ids;
use App\Support\LearnerDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `scheduling` module: the review ladder (docs/05 §4). One review item per learner x primary skill,
 * climbing 1 -> 3 -> 7 -> 21 days then 60. "Due" means eligible from that day; nothing is re-dated by
 * the cap, so a backlog spreads across later sessions and no overdue counter exists anywhere.
 */
final class ReviewService
{
    public function __construct(private ContentRepository $content) {}

    public static function interval(int $k): int
    {
        $ladder = config('devstep.review_ladder');

        return $k < count($ladder) ? (int) $ladder[$k] : (int) config('devstep.maintenance_interval_days');
    }

    /** Assistance band (docs/05 §2): unassisted | light | heavy | revealed. */
    public static function band(string $assistance, int $hintCount): string
    {
        return match (true) {
            $assistance === 'solution_revealed' => 'revealed',
            $assistance === 'worked_example' => 'heavy',
            $assistance === 'hint' && $hintCount >= (int) config('devstep.heavy_hint_threshold') => 'heavy',
            $assistance === 'hint' => 'light',
            default => 'unassisted',
        };
    }

    /** RV-1/RV-3: created when a skill is first taught; k = 0, due = learner day + 1. */
    public function ensureTrack(string $userId, object $item, string $day, ?string $enrolmentId = null): object
    {
        DB::insert(
            'INSERT INTO review_schedule (id, user_id, skill_id, objective_key, objective_major, alternate_group_key, interval_step, due_on, source_enrolment_id)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?) ON CONFLICT (user_id, objective_key, objective_major) DO NOTHING',
            [Ids::new(), $userId, $item->skill_id, $item->objective_key, $item->objective_major, $item->mission_key,
                LearnerDay::addDays($day, (int) config('devstep.initial_interval_days')), $enrolmentId],
        );

        return DB::selectOne('SELECT * FROM review_schedule WHERE user_id = ? AND objective_key = ? AND objective_major = ?', [$userId, $item->objective_key, $item->objective_major]);
    }

    /** In-mission or small-variant attempt that is not met or revealed: relearn, due within a day. */
    public function onInMissionNotMet(object $track, string $day): void
    {
        DB::update(
            'UPDATE review_schedule SET interval_step = 0, due_on = LEAST(due_on, ?::date), consecutive_not_met = consecutive_not_met + 1, updated_at = now() WHERE id = ?',
            [LearnerDay::addDays($day, 1), $track->id],
        );
    }

    /** RV-4: the mission activity is done, so the topic check becomes the skill's next retrieval. */
    public function onMissionActivityDone(string $userId, object $item, string $topicId, string $day, ?string $enrolmentId): void
    {
        $track = $this->ensureTrack($userId, $item, $day, $enrolmentId);
        DB::update(
            'UPDATE review_schedule SET check_pending_topic_id = ?, due_on = LEAST(due_on, ?::date), updated_at = now() WHERE id = ?',
            [$topicId, LearnerDay::addDays($day, (int) config('devstep.topic_check_delay_days')), $track->id],
        );
    }

    /**
     * Apply a due retrieval or topic-check outcome to the ladder (docs/05 §4.2).
     *
     * @return array{new_step:int, next_due:string, next_interval_days:int}
     */
    public function applyRetrieval(object $track, string $outcome, string $band, bool $isTopicCheck, string $day, string $itemVersionId, string $attemptId, bool $early = false): array
    {
        $k = (int) $track->interval_step;
        $met = Evaluator::isMet($outcome);
        $cfg = config('devstep');

        if ($early) {
            // Early recall (not due): success changes nothing; failure or reveal restarts the ladder.
            $new = ($met && $band !== 'revealed') ? $k : 0;
            $due = $new === $k && $met ? $track->due_on : LearnerDay::addDays($day, 1);
        } elseif ($band === 'revealed' || ! $met) {
            $new = 0;
            $due = LearnerDay::addDays($day, 1);
        } elseif ($band === 'heavy') {
            $new = max($k - (int) $cfg['heavy_assist_step_down'], 0);
            $due = LearnerDay::addDays($day, self::interval($new));
        } elseif ($band === 'light') {
            $new = $k;
            $due = LearnerDay::addDays($day, self::interval($new));
        } else {
            $new = $isTopicCheck ? max($k + 1, (int) $cfg['topic_check_pass_rung']) : $k + 1;
            $due = LearnerDay::addDays($day, self::interval($new));
        }

        $used = json_decode($track->used_items, true);
        $found = false;
        foreach ($used as &$u) {
            if ($u['item'] === $itemVersionId) {
                $u['times']++;
                $u['last'] = $day;
                $u['outcomes'][] = $outcome;
                $found = true;
            }
        }
        unset($u);
        if (! $found) {
            $used[] = ['item' => $itemVersionId, 'first' => $day, 'last' => $day, 'times' => 1, 'outcomes' => [$outcome]];
        }

        DB::update(
            'UPDATE review_schedule SET interval_step = ?, due_on = ?, last_item_version_id = ?, last_attempt_id = ?, last_outcome = ?,
                consecutive_not_met = ?, consecutive_check_failures = ?, consecutive_skips = 0, skipped_on = NULL,
                check_pending_topic_id = CASE WHEN ? AND ? THEN NULL ELSE check_pending_topic_id END, used_items = ?::jsonb, updated_at = now() WHERE id = ?',
            [$new, $due, $itemVersionId, $attemptId, $outcome,
                $met ? 0 : (int) $track->consecutive_not_met + 1,
                $isTopicCheck ? ($met ? 0 : (int) $track->consecutive_check_failures + 1) : (int) $track->consecutive_check_failures,
                $isTopicCheck, $met, json_encode($used), $track->id],
        );

        return ['new_step' => $new, 'next_due' => $due, 'next_interval_days' => self::interval($new)];
    }

    public function markDemonstrated(object $track, string $at, bool $retained): void
    {
        if ($retained) {
            DB::update('UPDATE review_schedule SET retained_at = ? WHERE id = ?', [$at, $track->id]);
        } else {
            DB::update('UPDATE review_schedule SET demonstrated_at = COALESCE(demonstrated_at, ?) WHERE id = ?', [$at, $track->id]);
        }
    }

    /**
     * Due items sorted by RV-10 and capped at read time. The cap never re-dates anything (docs/04 §5.6).
     *
     * @return list<object>
     */
    public function due(string $userId, string $day, int $limit, string $timeZone): array
    {
        if ($limit <= 0) {
            return [];
        }
        $rows = DB::select(
            "SELECT * FROM review_schedule WHERE user_id = ? AND status = 'active' AND due_on <= ? AND (skipped_on IS NULL OR skipped_on <> ?)",
            [$userId, $day, $day],
        );
        $deprioritise = (int) config('devstep.skip_deprioritise_after');
        usort($rows, function ($a, $b) use ($day, $deprioritise, $timeZone) {
            $key = fn ($r) => [
                $r->consecutive_skips >= $deprioritise ? 1 : 0,                       // 1 respect "not now"
                $r->check_pending_topic_id !== null ? 0 : 1,                          // 2 pending topic check completes topics
                $this->retentionEligible($r, $day, $timeZone) ? 0 : 1,                // 3 retention-eligible
                ($r->interval_step == 0 && $r->last_outcome !== null && ! Evaluator::isMet($r->last_outcome)) ? 0 : 1, // 4 relearning first
                -((LearnerDay::diffDays($r->due_on, $day)) / max(1, self::interval((int) $r->interval_step))), // 5 overdue ratio
                $r->due_on,                                                           // 7 earlier due date
                $r->skill_id,                                                         // 8 stable tie-breaker
            ];

            return $key($a) <=> $key($b);
        });

        return array_slice($rows, 0, $limit);
    }

    public function retentionEligible(object $track, string $day, string $timeZone): bool
    {
        if ($track->demonstrated_at === null || $track->retained_at !== null) {
            return false;
        }
        $demoDay = LearnerDay::of(CarbonImmutable::parse($track->demonstrated_at), $timeZone);

        return LearnerDay::diffDays($demoDay, $day) >= (int) config('devstep.retained_min_delay_days');
    }

    /**
     * Prompt rotation (docs/05 §4.3): never the same prompt twice in a row; a topic check uses a
     * check-eligible item; Alternate B is held for the delayed retention check.
     */
    public function choosePrompt(object $track, string $day, string $timeZone, bool $isTopicCheck): ?object
    {
        $mission = $this->content->latest('mission', $track->alternate_group_key);
        if (! $mission) {
            return null;
        }
        $pool = array_values(array_filter(
            $this->content->missionItems($mission),
            fn ($i) => in_array($i->role, ['topic_check', 'alternate'], true),
        ));
        $used = collect(json_decode($track->used_items, true))->keyBy('item');

        if ($isTopicCheck) {
            $pool = array_values(array_filter($pool, fn ($i) => $i->check_eligible));
        } else {
            $reserved = array_values(array_filter($pool, fn ($i) => $i->retention_reserved && ! $used->has($i->id)));
            if ($reserved && $this->retentionEligible($track, $day, $timeZone)) {
                return $reserved[0];
            }
            $pool = array_values(array_filter($pool, fn ($i) => ! $i->retention_reserved));
        }
        $pool = array_values(array_filter($pool, fn ($i) => $i->id !== $track->last_item_version_id));
        if (! $pool) {
            return null;
        }

        $prefer = $isTopicCheck ? ['topic_check', 'alternate'] : ['alternate', 'topic_check'];
        $rank = fn ($i) => array_search($i->role, $prefer, true);
        $fresh = array_values(array_filter($pool, fn ($i) => ! $used->has($i->id)));
        if ($fresh) {
            usort($fresh, fn ($a, $b) => [$rank($a), $a->content_key] <=> [$rank($b), $b->content_key]);

            return $fresh[0];
        }
        $reuseDays = (int) config('devstep.prompt_reuse_min_days');
        $spaced = array_values(array_filter($pool, fn ($i) => LearnerDay::diffDays($used[$i->id]['last'], $day) >= $reuseDays));
        $candidates = $spaced ?: $pool;
        usort($candidates, fn ($a, $b) => [$used[$a->id]['last'], $a->content_key] <=> [$used[$b->id]['last'], $b->content_key]);

        return $candidates[0];
    }
}
