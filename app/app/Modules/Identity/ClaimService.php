<?php

namespace App\Modules\Identity;

use App\Models\User;
use App\Modules\Analytics\EventRecorder;
use App\Modules\Assessment\Evaluator;
use App\Modules\Catalogue\ContentRepository;
use App\Modules\Catalogue\LearnerProjection;
use App\Modules\Learning\FeedbackAcknowledger;
use App\Modules\Profile\ProfileService;
use App\Modules\Roadmap\RoadmapService;
use App\Support\ApiException;
use App\Support\Clock;
use App\Support\Ids;
use App\Support\Learner;
use App\Support\LearnerDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Claims a browser's guest bundle after sign-up (docs/04 §7). The server NEVER trusts the client's own
 * results: every answer is re-evaluated against the pinned content version, and evidence is capped at
 * `practised` by a CHECK constraint, because answers could have been edited after seeing feedback.
 */
final class ClaimService
{
    private const NAMESPACE = '3b5c2a60-6c3d-4f5e-8b8a-2f1c9d0e7a41';

    public function __construct(
        private ContentRepository $content,
        private Evaluator $evaluator,
        private ProfileService $profile,
        private RoadmapService $roadmap,
        private FeedbackAcknowledger $ack,
    ) {}

    /** @return array{0:int, 1:array<string, mixed>} */
    public function claim(User $user, array $in): array
    {
        $learnerId = (string) $user->id;
        $mission = $this->content->version($in['sample']['content_version_id']);
        if (! $mission || $mission->content_kind !== 'mission' || $mission->content_key !== config('devstep.sample_mission')) {
            throw new ApiException('claim_invalid', 'That progress is from a version of the sample we can no longer read.', 422);
        }
        $items = collect($this->content->missionItems($mission))->keyBy('id');
        $attempts = $in['sample']['attempts'] ?? [];
        if (count($attempts) > (int) config('devstep.guest_bundle_max_attempts')) {
            throw new ApiException('claim_invalid', 'That bundle has too many answers.', 422);
        }

        // 1. Onboarding answers fill preferences and goal only where the account has none.
        $ob = $in['onboarding'] ?? [];
        $tz = $ob['time_zone'] ?? 'UTC';
        $firstOnboarding = false;
        if (! empty($ob)) {
            $firstOnboarding = $this->profile->saveIfAbsent($learnerId, [
                'time_zone' => $tz, 'available_days' => $ob['available_days'] ?? [1, 2, 3, 4, 5],
                'preferred_mode' => $ob['preferred_mode'] ?? 'practise', 'weekly_session_target' => $ob['weekly_session_target'] ?? 3,
                'stack_tags' => $ob['stack_tags'] ?? [], 'diagnostic_status' => 'skipped',
            ]);
            if (! empty($ob['goal']['outcome_key'])) {
                $this->profile->saveIfNoGoal($learnerId, $ob['goal']['outcome_key'], $ob['goal']['note'] ?? null);
            }
        } elseif (! DB::selectOne('SELECT 1 FROM learning_preferences WHERE user_id = ?', [$learnerId])) {
            $firstOnboarding = $this->profile->saveIfAbsent($learnerId, [
                'time_zone' => 'UTC', 'available_days' => [1, 2, 3, 4, 5], 'preferred_mode' => 'practise', 'weekly_session_target' => 3, 'stack_tags' => [], 'diagnostic_status' => 'skipped',
            ]);
        }
        $learner = Learner::of($user);

        // 2. Enrol in the single launch roadmap, pinned to its current published version (R01).
        [$enrolment] = $this->roadmap->enrol($learnerId, $learner->subjectId);

        $already = DB::selectOne("SELECT id FROM learning_sessions WHERE user_id = ? AND purpose = 'sample'", [$learnerId]);
        $changes = ['evidence' => [], 'topics' => []];
        $claimed = 0;

        if (! $already && $attempts) {
            $now = Clock::now();
            $times = array_map(fn ($a) => $this->clamp($a['answered_at'] ?? null, $now), $attempts);
            sort($times);
            $first = $times[0];
            $last = end($times);
            $sessionId = Ids::new();
            $plan = $this->samplePlan($mission);
            DB::insert(
                "INSERT INTO learning_sessions (id, user_id, mode, purpose, status, enrolment_id, topic_id, content_version_id, plan, local_date, started_at, last_activity_at, completed_at)
                 VALUES (?, ?, 'practise', 'sample', 'completed', ?, NULL, ?, ?::jsonb, ?, ?, ?, ?)",
                [$sessionId, $learnerId, $enrolment->id, $mission->id, json_encode($plan), LearnerDay::of($first, $learner->timeZone), $first, $last, $last],
            );

            $toAck = [];
            foreach ($attempts as $a) {
                $item = $items[$a['assessment_item_id']] ?? null;
                $step = collect($plan['steps'])->firstWhere('item_cv', $a['assessment_item_id']);
                if (! $item || ! $step || $item->role !== 'primary') {
                    throw new ApiException('claim_invalid', 'One of the answers is not part of the sample.', 422);
                }
                // Re-evaluate: the client's own result is ignored.
                $eval = $this->evaluator->evaluate($item->body, $a['answer'], LearnerProjection::misconceptions($mission));
                $hints = min(max((int) ($a['hints_used'] ?? 0), 0), count($item->body['hints'] ?? []));
                $revealed = (bool) ($a['revealed'] ?? false);
                $assistance = $revealed ? 'solution_revealed' : ($hints > 0 ? 'hint' : 'none');
                $answeredAt = $this->clamp($a['answered_at'] ?? null, $now);
                $clientId = isset($a['client_attempt_id']) && Uuid::isValid($a['client_attempt_id']) ? $a['client_attempt_id'] : Uuid::uuid5(self::NAMESPACE, $learnerId.'|'.$item->id)->toString();

                $attemptId = Ids::new();
                $inserted = DB::selectOne(
                    "INSERT INTO attempts (id, user_id, session_id, content_version_id, step_key, attempt_no, client_attempt_id, purpose, origin, response, outcome, score, evaluated_at,
                        evidence_basis, assistance, hint_count, submitted_at)
                     VALUES (?, ?, ?, ?, ?, 1, ?, 'in_mission', 'guest_claim', ?::jsonb, ?, ?, ?, ?, ?, ?, ?)
                     ON CONFLICT (user_id, client_attempt_id) DO NOTHING RETURNING id",
                    [$attemptId, $learnerId, $sessionId, $item->id, $step['key'], $clientId, json_encode($a['answer']), $eval['outcome'], $eval['score'], $answeredAt,
                        $eval['basis'], $assistance, $hints, $answeredAt],
                );
                if (! $inserted) {
                    continue; // a re-sent bundle after the key expired: the unique key stops duplicates
                }
                for ($h = 1; $h <= $hints; $h++) {
                    DB::insert("INSERT INTO session_assistance (id, user_id, session_id, step_key, content_version_id, kind, hint_index, used_at) VALUES (?, ?, ?, ?, ?, 'hint', ?, ?) ON CONFLICT DO NOTHING", [Ids::new(), $learnerId, $sessionId, $step['key'], $item->id, $h, $answeredAt]);
                }
                if ($revealed) {
                    DB::insert("INSERT INTO session_assistance (id, user_id, session_id, step_key, content_version_id, kind, hint_index, used_at) VALUES (?, ?, ?, ?, ?, 'solution_revealed', 0, ?) ON CONFLICT DO NOTHING", [Ids::new(), $learnerId, $sessionId, $step['key'], $item->id, $answeredAt]);
                }
                $claimed++;
                if (! empty($a['feedback_viewed'])) {
                    $toAck[] = DB::selectOne('SELECT * FROM attempts WHERE id = ?', [$attemptId]);
                }
            }
            $changes = $this->ack->acknowledge($learner, $toAck, $enrolment, 'practise');
        }

        $summary = [
            'claimed_attempts' => $claimed,
            'evidence_changes' => $changes['evidence'],
            'enrolment_id' => $enrolment->id,
            'sample_completed' => $claimed > 0 || $already !== null,
        ];

        $guestSubject = $in['subject_id'] ?? null;
        DB::afterCommit(function () use ($learner, $guestSubject, $claimed, $firstOnboarding, $already) {
            if ($guestSubject) {
                EventRecorder::adoptGuestSubject($guestSubject, $learner->subjectId);
            }
            if (! $already) {
                EventRecorder::server('guest_progress_claimed', $learner->subjectId, [
                    'attempts_claimed' => min($claimed, 100), 'sample_completed' => $claimed > 0, 'into_existing_account' => ! $firstOnboarding,
                ], [], $learner->id);
            }
        });

        return [200, $summary];
    }

    /** Freeze the sample's practise steps like a normal session plan so the claimed session reads back. */
    private function samplePlan(object $mission): array
    {
        $front = $mission->body['front'];
        $items = collect($this->content->missionItems($mission))->keyBy('content_key');
        $steps = [];
        foreach ($front['steps'] as $s) {
            if (! in_array('practise', $s['modes'] ?? ['practise'], true)) {
                continue;
            }
            $steps[] = [
                'index' => count($steps), 'key' => $s['key'], 'kind' => $s['kind'], 'activity' => 0, 'purpose' => 'in_mission',
                'item_cv' => $s['kind'] === 'response' ? $items[$s['item']]->id : null, 'mission_cv' => $mission->id, 'stop_after' => (bool) ($s['stop_after'] ?? false),
            ];
        }

        return ['reason' => 'SAMPLE_SCENARIO', 'mode' => 'practise', 'minutes' => 8, 'reviews' => 0, 'activities' => [['kind' => 'mission', 'title' => $front['title']]], 'steps' => $steps, 'topic_id' => null, 'content_version_id' => $mission->id, 'context' => 'Sample scenario', 'purpose' => 'sample'];
    }

    /** A guest's clock is not trusted: answer times are kept within the last 30 days and never in the future. */
    private function clamp(?string $at, CarbonImmutable $now): CarbonImmutable
    {
        try {
            $t = $at ? CarbonImmutable::parse($at)->utc() : $now;
        } catch (\Throwable) {
            $t = $now;
        }
        if ($t->greaterThan($now)) {
            return $now;
        }

        return $t->lessThan($now->subDays(30)) ? $now->subDays(30) : $t;
    }
}
