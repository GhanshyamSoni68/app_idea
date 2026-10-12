<?php

namespace App\Modules\Assessment;

use App\Support\Clock;
use App\Support\Ids;
use App\Support\LearnerDay;
use Illuminate\Support\Facades\DB;

/**
 * Writes append-only skill evidence from attempts (docs/05 §6, F04, F08).
 * Reading never raises a level; a reveal never demonstrates; claimed guest work is capped at practised.
 */
final class EvidenceService
{
    private const RANK = ['introduced' => 1, 'practised' => 2, 'demonstrated' => 3, 'retained' => 4];
    private const DEMONSTRATING_PURPOSES = ['retrieval', 'topic_check', 'challenge', 'lab'];

    /**
     * Record the evidence an attempt earns. Called once feedback is acknowledged ("engaged with feedback").
     *
     * @param  object  $attempt  attempts row (session origin or guest_claim)
     * @param  object  $item  ContentRepository::itemMeta() (flags, scenario_key, skill_id, objective)
     * @param  string  $teachingScenario  scenario_key of the mission that teaches this skill
     * @return array{skill_id:string, from_level:?string, to_level:string, basis:string}|null
     */
    public function recordForAttempt(object $attempt, object $item, string $teachingScenario, string $timeZone): ?array
    {
        if (in_array($attempt->purpose, ['diagnostic', 'transfer'], true) || (int) $attempt->attempt_no !== 1) {
            return null; // baseline/measurement answers and retries after feedback earn nothing (docs/05 §6.3)
        }
        if (DB::selectOne('SELECT 1 FROM skill_evidence WHERE attempt_id = ?', [$attempt->id])) {
            return null; // one evidence row per attempt
        }

        $assistance = $attempt->assistance;
        $met = Evaluator::isMet((string) $attempt->outcome);
        $limitations = [];
        if ($assistance === 'hint') {
            $limitations[] = $attempt->hint_count >= config('devstep.heavy_hint_threshold') ? 'heavy_assistance' : 'light_assistance';
        }
        if ($assistance === 'worked_example') {
            $limitations[] = 'heavy_assistance';
        }
        if ($attempt->purpose === 'small_variant') {
            $limitations[] = 'small_mode';
        }

        $level = 'practised';
        if ($assistance === 'solution_revealed') {
            $level = 'introduced'; // a reveal before answering earns nothing above introduced
            $limitations[] = 'solution_revealed';
        } elseif ($attempt->origin === 'guest_claim') {
            $level = 'practised';
            $limitations[] = 'guest_claim';
        } elseif ($met && $assistance === 'none' && in_array($attempt->purpose, self::DEMONSTRATING_PURPOSES, true)
            && $item->scenario_key !== $teachingScenario) {
            $level = 'demonstrated';
            $retained = $this->retainedCondition($attempt, $item, $timeZone);
            if ($retained) {
                $level = 'retained';
            }
        }

        $before = $this->highestLevel($attempt->user_id, $item->skill_id);
        DB::insert(
            'INSERT INTO skill_evidence (id, user_id, skill_id, level, basis, assistance, source, attempt_id, content_version_id, objective_key, objective_major, limitations, observed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?)',
            [Ids::new(), $attempt->user_id, $item->skill_id, $level, $attempt->evidence_basis, $assistance,
                $attempt->origin === 'guest_claim' ? 'guest_claim' : 'attempt', $attempt->id, $attempt->content_version_id,
                $item->objective_key, $item->objective_major, json_encode($limitations), $attempt->submitted_at],
        );

        return ['skill_id' => $item->skill_id, 'from_level' => $before, 'to_level' => $level, 'basis' => $attempt->evidence_basis];
    }

    /** retained: unassisted met on a different item, >= 7 learner days after an earlier demonstration (docs/05 §6.1). */
    private function retainedCondition(object $attempt, object $item, string $timeZone): bool
    {
        $earlier = DB::selectOne(
            "SELECT se.observed_at, se.content_version_id FROM skill_evidence se
             WHERE se.user_id = ? AND se.skill_id = ? AND se.level IN ('demonstrated', 'retained') AND se.voided_at IS NULL
             ORDER BY se.observed_at ASC LIMIT 1",
            [$attempt->user_id, $item->skill_id],
        );
        if (! $earlier || $earlier->content_version_id === $attempt->content_version_id) {
            return false;
        }
        $then = LearnerDay::of(\Carbon\CarbonImmutable::parse($earlier->observed_at), $timeZone);
        $now = LearnerDay::of(\Carbon\CarbonImmutable::parse($attempt->submitted_at), $timeZone);

        return LearnerDay::diffDays($then, $now) >= (int) config('devstep.retained_min_delay_days');
    }

    public function highestLevel(string $userId, string $skillId): ?string
    {
        $row = DB::selectOne(
            "SELECT level FROM skill_evidence WHERE user_id = ? AND skill_id = ? AND voided_at IS NULL
             ORDER BY CASE level WHEN 'retained' THEN 4 WHEN 'demonstrated' THEN 3 WHEN 'practised' THEN 2 ELSE 1 END DESC LIMIT 1",
            [$userId, $skillId],
        );

        return $row->level ?? null;
    }

    /**
     * The Evidence view (F08): per skill, the highest level ever reached with dates, strongest basis,
     * limitations and a neutral next-practice date. Levels never drop (EV-2); a later unmet check adds
     * "refresh suggested" (EV-3).
     *
     * @return list<array<string, mixed>>
     */
    public function view(string $userId): array
    {
        $rows = DB::select(
            "SELECT se.*, s.skill_key, s.title, a.outcome AS attempt_outcome
             FROM skill_evidence se JOIN skills s ON s.id = se.skill_id LEFT JOIN attempts a ON a.id = se.attempt_id
             WHERE se.user_id = ? AND se.voided_at IS NULL ORDER BY se.observed_at ASC",
            [$userId],
        );
        $bySkill = [];
        foreach ($rows as $r) {
            $bySkill[$r->skill_id][] = $r;
        }
        $reviews = collect(DB::select('SELECT skill_id, due_on FROM review_schedule WHERE user_id = ?', [$userId]))->keyBy('skill_id');
        $basisOrder = ['human_reviewed' => 1, 'auto_scored' => 2, 'learner_submitted' => 3, 'self_assessed' => 4];

        $out = [];
        foreach ($bySkill as $skillId => $events) {
            $top = null;
            foreach ($events as $e) {
                if ($top === null || self::RANK[$e->level] > self::RANK[$top->level]) {
                    $top = $e;
                }
            }
            $atTop = array_values(array_filter($events, fn ($e) => $e->level === $top->level));
            $bases = array_values(array_unique(array_map(fn ($e) => $e->basis, $atTop)));
            usort($bases, fn ($a, $b) => $basisOrder[$a] <=> $basisOrder[$b]);
            $latest = end($events);
            $limitations = [];
            foreach ($atTop as $e) {
                $limitations = array_merge($limitations, json_decode($e->limitations, true));
            }
            $refresh = in_array($top->level, ['demonstrated', 'retained'], true)
                && $latest->attempt_outcome !== null && ! Evaluator::isMet($latest->attempt_outcome)
                && in_array($latest->source, ['attempt', 'human_review'], true);

            $out[] = [
                'skill_id' => $skillId,
                'skill_key' => $top->skill_key,
                'title' => $top->title,
                'level' => $top->level,
                'first_achieved_at' => $atTop[0]->observed_at,
                'last_confirmed_at' => end($atTop)->observed_at,
                'basis' => $bases[0],
                'also_basis' => array_slice($bases, 1),
                'assistance' => $top->assistance,
                'limitations' => array_values(array_unique($limitations)),
                'refresh_suggested' => $refresh,
                'content_version_id' => $top->content_version_id,
                'next_practice_on' => isset($reviews[$skillId]) ? $reviews[$skillId]->due_on : null,
            ];
        }
        usort($out, fn ($a, $b) => strcmp($a['skill_key'], $b['skill_key']));

        return $out;
    }
}
