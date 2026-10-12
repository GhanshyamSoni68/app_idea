<?php

namespace App\Modules\Roadmap;

use App\Modules\Analytics\EventRecorder;
use App\Support\ApiException;
use App\Support\Clock;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `roadmap` module: enrolment pinned to a published roadmap version (R01), topic progress and
 * exactly-once completion (R02), prerequisites and milestone (R04). Progress is completed required
 * topics / required topics in the pinned version, rounded down, with the count shown.
 */
final class RoadmapService
{
    public function current(string $userId): ?object
    {
        return DB::selectOne("SELECT * FROM roadmap_enrolments WHERE user_id = ? AND status IN ('active', 'paused')", [$userId]);
    }

    public function find(string $userId, string $enrolmentId): object
    {
        $row = DB::selectOne('SELECT * FROM roadmap_enrolments WHERE id = ? AND user_id = ?', [$enrolmentId, $userId]);
        if (! $row) {
            throw ApiException::notFound('That enrolment');
        }

        return $row;
    }

    /** Enrol in the current published version of the launch roadmap. Returns [enrolment, created]. */
    public function enrol(string $userId, string $subjectId, ?string $slug = null): array
    {
        $slug ??= config('devstep.launch_roadmap');

        return DB::transaction(function () use ($userId, $subjectId, $slug) {
            $existing = $this->current($userId);
            if ($existing) {
                return [$existing, false];
            }
            $version = DB::selectOne(
                "SELECT rv.* FROM roadmap_versions rv JOIN roadmaps r ON r.id = rv.roadmap_id
                 WHERE r.slug = ? AND rv.status = 'published' ORDER BY rv.version DESC LIMIT 1",
                [$slug],
            );
            if (! $version) {
                throw new ApiException('roadmap_unavailable', 'That roadmap is not available yet.', 404);
            }
            $id = Ids::new();
            $now = Clock::now();
            DB::insert(
                "INSERT INTO roadmap_enrolments (id, user_id, roadmap_id, roadmap_version_id, status, enrolled_at, created_at, updated_at) VALUES (?, ?, ?, ?, 'active', ?, ?, ?)",
                [$id, $userId, $version->roadmap_id, $version->id, $now, $now, $now],
            );
            DB::insert(
                'INSERT INTO topic_progress (id, user_id, enrolment_id, roadmap_version_id, topic_id)
                 SELECT gen_random_uuid(), ?, ?, ?, id FROM roadmap_topics WHERE roadmap_version_id = ?',
                [$userId, $id, $version->id, $version->id],
            );
            $enrolment = DB::selectOne('SELECT * FROM roadmap_enrolments WHERE id = ?', [$id]);
            DB::afterCommit(fn () => EventRecorder::server('roadmap_enrolled', $subjectId, [
                'roadmap_enrolment_id' => $id, 'roadmap_id' => $version->roadmap_id, 'roadmap_version_id' => $version->id,
                'required_topic_count' => (int) $version->required_topic_count,
            ], ['roadmap_version_id' => $version->id]));

            return [$enrolment, true];
        });
    }

    /** @return array{completed_required:int,total_required:int,percent:int} */
    public function progress(object $enrolment): array
    {
        $total = (int) DB::scalar('SELECT required_topic_count FROM roadmap_versions WHERE id = ?', [$enrolment->roadmap_version_id]);
        $done = (int) DB::scalar(
            "SELECT count(*) FROM topic_progress tp JOIN roadmap_topics t ON t.id = tp.topic_id
             WHERE tp.enrolment_id = ? AND t.kind = 'required' AND tp.state = 'completed'",
            [$enrolment->id],
        );

        return ['completed_required' => $done, 'total_required' => $total, 'percent' => $total > 0 ? (int) floor($done * 100 / $total) : 0];
    }

    /**
     * The Roadmap view: modules and topics in order, with state, prerequisites and readiness (F-8A).
     *
     * @return array<string, mixed>
     */
    public function view(object $enrolment): array
    {
        $version = DB::selectOne('SELECT * FROM roadmap_versions WHERE id = ?', [$enrolment->roadmap_version_id]);
        $modules = DB::select('SELECT * FROM roadmap_modules WHERE roadmap_version_id = ? ORDER BY position', [$version->id]);
        $topics = DB::select(
            "SELECT t.*, tp.state, tp.completion_path, tp.completed_at, tp.mission_activity_done_at,
                    EXISTS (SELECT 1 FROM topic_activities ta JOIN content_versions cv ON cv.content_kind = 'mission' AND cv.content_key = ta.ref_key
                            AND cv.version_major = ta.ref_major AND cv.status = 'published'
                            WHERE ta.topic_id = t.id AND ta.role = 'core') AS has_content
             FROM roadmap_topics t JOIN topic_progress tp ON tp.topic_id = t.id AND tp.enrolment_id = ?
             WHERE t.roadmap_version_id = ? ORDER BY t.position",
            [$enrolment->id, $version->id],
        );
        $deps = DB::select(
            'SELECT d.topic_id, d.depends_on_topic_id, d.strict, t.topic_key, t.title FROM topic_dependencies d JOIN roadmap_topics t ON t.id = d.depends_on_topic_id WHERE d.roadmap_version_id = ?',
            [$version->id],
        );
        $depsByTopic = [];
        foreach ($deps as $d) {
            $depsByTopic[$d->topic_id][] = $d;
        }
        $stateById = [];
        foreach ($topics as $t) {
            $stateById[$t->id] = $t;
        }

        $outModules = [];
        foreach ($modules as $m) {
            $outTopics = [];
            foreach ($topics as $t) {
                if ($t->module_id !== $m->id) {
                    continue;
                }
                $unmet = [];
                foreach ($depsByTopic[$t->id] ?? [] as $d) {
                    if (! $this->dependencySatisfied($stateById[$d->depends_on_topic_id], (bool) $d->strict)) {
                        $unmet[] = ['topic_key' => $d->topic_key, 'title' => $d->title, 'strict' => (bool) $d->strict];
                    }
                }
                $outTopics[] = [
                    'id' => $t->id,
                    'topic_key' => $t->topic_key,
                    'title' => $t->title,
                    'kind' => $t->kind,
                    'estimated_minutes' => (int) $t->estimated_minutes,
                    'state' => $t->state,
                    'completion_path' => $t->completion_path,
                    'mission_activity_done' => $t->mission_activity_done_at !== null,
                    'has_content' => (bool) $t->has_content,
                    'prerequisites' => array_map(fn ($d) => ['topic_key' => $d->topic_key, 'title' => $d->title, 'strict' => (bool) $d->strict], $depsByTopic[$t->id] ?? []),
                    'unmet_prerequisites' => $unmet,
                    'ready' => $unmet === [],
                ];
            }
            $outModules[] = ['module_key' => $m->module_key, 'title' => $m->title, 'topics' => $outTopics];
        }

        return [
            'enrolment' => [
                'id' => $enrolment->id,
                'status' => $enrolment->status,
                'completed_at' => $enrolment->completed_at,
                'roadmap_version_id' => $version->id,
            ],
            'roadmap' => [
                'title' => $version->title,
                'outcome' => $version->outcome,
                'estimated_effort' => $version->estimated_effort_text,
                'coverage_note' => $version->coverage_note,
                'version' => (int) $version->version,
            ],
            'progress' => $this->progress($enrolment),
            'modules' => $outModules,
        ];
    }

    /** PR-1/PR-2: soft = mission activity done or completed; strict = completed. A deferred topic never counts. */
    public function dependencySatisfied(object $dep, bool $strict): bool
    {
        if ($dep->state === 'completed') {
            return true;
        }
        if ($strict || $dep->state === 'deferred' && $dep->mission_activity_done_at === null) {
            return false;
        }

        return $dep->mission_activity_done_at !== null;
    }

    /**
     * Required topics in roadmap order that are not completed or deferred, whose mission activity is not
     * yet done, that are prerequisite-ready and have published content (docs/05 §7.3).
     *
     * @return list<object>
     */
    public function readyTopics(object $enrolment): array
    {
        $rows = DB::select(
            "SELECT t.id, t.topic_key, t.title, t.position, t.objective_key, t.objective_major, tp.state, tp.mission_activity_done_at, m.title AS module_title, m.position AS module_position,
                    ta.ref_key AS mission_key, ta.ref_major AS mission_major
             FROM roadmap_topics t
             JOIN topic_progress tp ON tp.topic_id = t.id AND tp.enrolment_id = ?
             JOIN roadmap_modules m ON m.id = t.module_id
             LEFT JOIN topic_activities ta ON ta.topic_id = t.id AND ta.role = 'core'
             WHERE t.roadmap_version_id = ? AND t.kind = 'required' AND tp.state IN ('not_started', 'in_progress') AND tp.mission_activity_done_at IS NULL
             ORDER BY t.position",
            [$enrolment->id, $enrolment->roadmap_version_id],
        );
        $all = DB::select(
            'SELECT t.id, tp.state, tp.mission_activity_done_at FROM roadmap_topics t JOIN topic_progress tp ON tp.topic_id = t.id AND tp.enrolment_id = ? WHERE t.roadmap_version_id = ?',
            [$enrolment->id, $enrolment->roadmap_version_id],
        );
        $state = [];
        foreach ($all as $a) {
            $state[$a->id] = $a;
        }
        $deps = DB::select('SELECT topic_id, depends_on_topic_id, strict FROM topic_dependencies WHERE roadmap_version_id = ?', [$enrolment->roadmap_version_id]);
        $depsBy = [];
        foreach ($deps as $d) {
            $depsBy[$d->topic_id][] = $d;
        }

        $ready = [];
        foreach ($rows as $r) {
            foreach ($depsBy[$r->id] ?? [] as $d) {
                if (! $this->dependencySatisfied($state[$d->depends_on_topic_id], (bool) $d->strict)) {
                    continue 2;
                }
            }
            $ready[] = $r;
        }

        return $ready;
    }

    public function topicByMission(object $enrolment, string $missionKey): ?object
    {
        return DB::selectOne(
            "SELECT t.*, tp.state, tp.mission_activity_done_at, tp.mission_activity_done_on
             FROM roadmap_topics t JOIN topic_progress tp ON tp.topic_id = t.id AND tp.enrolment_id = ?
             JOIN topic_activities ta ON ta.topic_id = t.id AND ta.role = 'core' AND ta.ref_key = ?
             WHERE t.roadmap_version_id = ?",
            [$enrolment->id, $missionKey, $enrolment->roadmap_version_id],
        );
    }

    public function topicByCheckMission(object $enrolment, string $missionKey): ?object
    {
        return $this->topicByMission($enrolment, $missionKey);
    }

    /** First activity on a topic moves not_started -> in_progress (R02). Event fires once. */
    public function markStarted(object $enrolment, object $topic, string $subjectId): void
    {
        $changed = DB::update(
            "UPDATE topic_progress SET state = 'in_progress', started_at = ? WHERE enrolment_id = ? AND topic_id = ? AND state IN ('not_started', 'deferred')",
            [Clock::now(), $enrolment->id, $topic->id],
        );
        if ($changed === 1) {
            DB::afterCommit(fn () => EventRecorder::server('topic_started', $subjectId, [
                'topic_id' => $topic->id, 'topic_kind' => $topic->kind, 'roadmap_version_id' => $enrolment->roadmap_version_id,
            ], ['roadmap_version_id' => $enrolment->roadmap_version_id, 'topic_key' => $topic->topic_key], $enrolment->id.'|'.$topic->id));
        }
    }

    /**
     * C1 + C2 satisfied for this topic (docs/05 TC-1): sticky once set. Returns true when newly set.
     */
    public function markMissionActivityDone(object $enrolment, object $topic, string $day): bool
    {
        return DB::update(
            'UPDATE topic_progress SET mission_activity_done_at = ?, mission_activity_done_on = ? WHERE enrolment_id = ? AND topic_id = ? AND mission_activity_done_at IS NULL',
            [Clock::now(), $day, $enrolment->id, $topic->id],
        ) === 1;
    }

    /**
     * Exactly-once completion by the standard path (docs/04 §5.2): one conditional UPDATE decides. Then the
     * milestone is checked with the same pattern. Events go out after commit with deterministic IDs.
     *
     * @return array{topic_id:string, from_state:string, to_state:string}|null null when it was already completed
     */
    public function completeTopic(object $enrolment, object $topic, string $path, ?string $attemptId, string $subjectId, string $basis = 'rules_met'): ?array
    {
        $before = DB::scalar('SELECT state FROM topic_progress WHERE enrolment_id = ? AND topic_id = ?', [$enrolment->id, $topic->id]);
        $row = DB::selectOne(
            "UPDATE topic_progress SET state = 'completed', completed_at = ?, completion_path = ?, completion_attempt_id = ?
             WHERE enrolment_id = ? AND topic_id = ? AND user_id = ? AND state <> 'completed' RETURNING id",
            [Clock::now(), $path, $attemptId, $enrolment->id, $topic->id, $enrolment->user_id],
        );
        if (! $row) {
            return null;
        }
        $progress = $this->progress($enrolment);
        DB::afterCommit(fn () => EventRecorder::server('topic_completed', $subjectId, [
            'topic_id' => $topic->id, 'topic_kind' => $topic->kind, 'completion_basis' => $basis,
            'required_completed' => $progress['completed_required'], 'required_total' => $progress['total_required'],
        ], ['roadmap_version_id' => $enrolment->roadmap_version_id, 'topic_key' => $topic->topic_key], $enrolment->id.'|'.$topic->id));

        $this->checkMilestone($enrolment, $subjectId);

        return ['topic_id' => $topic->id, 'from_state' => $before, 'to_state' => 'completed'];
    }

    /** R04: awarded once, never revoked. Same conditional-update pattern as topic completion. */
    private function checkMilestone(object $enrolment, string $subjectId): void
    {
        $p = $this->progress($enrolment);
        if ($p['completed_required'] < $p['total_required']) {
            return;
        }
        $now = Clock::now();
        $won = DB::update(
            'UPDATE roadmap_enrolments SET completed_at = ?, completion_summary = ?::jsonb, updated_at = ? WHERE id = ? AND completed_at IS NULL',
            [$now, json_encode(['completed_required' => $p['completed_required'], 'total_required' => $p['total_required'], 'note' => 'Coverage with checked understanding; not verified implementation proficiency.']), $now, $enrolment->id],
        );
        if ($won === 1) {
            $days = (int) CarbonImmutable::parse($enrolment->enrolled_at)->diffInDays($now);
            DB::afterCommit(fn () => EventRecorder::server('roadmap_completed', $subjectId, [
                'roadmap_enrolment_id' => $enrolment->id, 'roadmap_version_id' => $enrolment->roadmap_version_id, 'elapsed_days' => $days,
            ], ['roadmap_version_id' => $enrolment->roadmap_version_id], $enrolment->id));
        }
    }
}
