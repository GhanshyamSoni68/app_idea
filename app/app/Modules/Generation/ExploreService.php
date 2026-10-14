<?php

namespace App\Modules\Generation;

use App\Jobs\DraftExploreMission;
use App\Jobs\GenerateExplorePath;
use App\Modules\Analytics\EventRecorder;
use App\Modules\Catalogue\ContentPublisher;
use App\Modules\Catalogue\ContentValidator;
use App\Modules\Generation\Pipeline\ContentAssembler;
use App\Modules\Generation\Pipeline\TopicGuard;
use App\Modules\Roadmap\RoadmapService;
use App\Support\ApiException;
use App\Support\Clock;
use App\Support\Learner;
use Illuminate\Support\Facades\DB;

/**
 * "Learn something else" (docs/16 §8): a learner names a topic, a path is made for them in the background, and they choose
 * whether to start it. It never changes anything the learner already has until they press Start.
 *
 *   request()   guards the topic, records a run, queues the work                       (HTTP)
 *   run()       plan -> first mission -> publish the path with that mission -> ready  (queue)
 *   draftLater  the remaining topics, one job each, published as they are accepted    (queue)
 *   backfill()  retries a topic that could not be made, a limited number of times     (scheduler)
 *   start()     enrols the learner in their path and makes it their focus              (HTTP)
 */
final class ExploreService
{
    public const LEVELS = ['new' => 'new to it', 'some' => 'some experience', 'experienced' => 'experienced'];

    private const MAX_ATTEMPTS_PER_TOPIC = 3;

    public function __construct(
        private GenerationService $generation,
        private GenerationLog $log,
        private ContentPublisher $publisher,
        private ContentValidator $validator,
        private RoadmapService $roadmaps,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('generation.enabled');
    }

    /** @return string the run id */
    public function request(Learner $learner, string $rawTopic, string $level, string $consent): string
    {
        if (! self::enabled()) {
            throw new ApiException('explore_unavailable', 'Making a path on your own topic is not available yet.', 404);
        }
        $topic = TopicGuard::normalise($rawTopic);
        if ($this->log->userRunsToday($learner->id) >= (int) config('generation.budget.per_user_daily_runs')) {
            throw GenerationException::budget('user');
        }
        $busy = DB::selectOne("SELECT 1 FROM generation_runs WHERE user_id = ? AND kind = 'roadmap' AND status IN ('queued', 'running') AND created_at > ?", [$learner->id, Clock::now()->subMinutes(20)]);
        if ($busy) {
            throw ApiException::conflict('explore_busy', 'We are still making your last path. It will be ready in a moment.');
        }

        $runId = $this->log->startRun('roadmap', $learner->id, $topic, ['level' => $level, 'minutes' => 10, 'consent' => $consent]);
        EventRecorder::server('explore_requested', $learner->subjectId, ['level' => $level]);
        dispatch(new GenerateExplorePath($runId));

        return $runId;
    }

    /** The queue job. Safe to run twice: only a queued run starts. */
    public function run(string $runId): void
    {
        $run = DB::selectOne("SELECT * FROM generation_runs WHERE id = ? AND kind = 'roadmap' AND status = 'queued'", [$runId]);
        if (! $run) {
            return;
        }
        $params = json_decode($run->params, true);
        $level = self::LEVELS[$params['level'] ?? 'some'] ?? 'some experience';
        $tally = new RunTally($runId);
        $started = Clock::now();

        try {
            $planned = $this->generation->planRoadmap($run->user_id, (string) $run->topic, ['level' => $level, 'minutes' => (int) ($params['minutes'] ?? 10)], $runId, false);
            $tally = $planned['tally'];
            $first = $planned['plan']['topics'][0];
            $this->log->stage($runId, 'first-mission');
            $r = $this->generation->draftMission($run->user_id, $runId, $planned, $first['key'], ['level' => $level]);
            if ($r['status'] !== 'accepted') {
                throw GenerationException::notGoodEnough($r['findings']);
            }

            $this->log->stage($runId, 'publishing');
            $this->publish($run->user_id, ContentAssembler::bundle($planned['roadmap'], [$r['mission']['front']['id'] => $r['mission']]), [$planned['artifact_id'], $r['artifact_id']]);
            $this->log->finish($runId, 'accepted', null, null, $tally);
            $this->emit($run->user_id, 'explore_ready', ['topic_count' => count($planned['plan']['topics']), 'seconds' => (int) min(86400, $started->diffInSeconds(Clock::now()))]);

            foreach (array_slice($planned['plan']['topics'], 1) as $t) {
                dispatch(new DraftExploreMission($runId, $t['key']));
            }
        } catch (GenerationException $e) {
            $this->failed($runId, $run->user_id, $e);
        } catch (\Throwable $e) {
            report($e);
            $this->failed($runId, $run->user_id, new GenerationException('unexpected', 'We could not create that path right now. Nothing was changed. Please try again later.', ['error' => $e::class]));
        }
    }

    /** Draft one more topic of a path that is already published. Idempotent. */
    public function draftLater(string $runId, string $topicKey): void
    {
        $run = DB::selectOne("SELECT * FROM generation_runs WHERE id = ? AND kind = 'roadmap' AND status = 'accepted'", [$runId]);
        $plan = DB::selectOne("SELECT * FROM generated_artifacts WHERE run_id = ? AND kind = 'roadmap_plan' AND published_release_id IS NOT NULL", [$runId]);
        if (! $run || ! $plan || $this->hasMission($runId, $topicKey)) {
            return;
        }
        $content = json_decode($plan->content, true);
        $params = json_decode($run->params, true);
        $level = self::LEVELS[$params['level'] ?? 'some'] ?? 'some experience';
        try {
            $r = $this->generation->draftMission($run->user_id, $runId, ['plan' => $content['plan'], 'roadmap' => $content['roadmap']], $topicKey, ['level' => $level]);
        } catch (GenerationException) {
            return; // the run is already logged; the backfill tries again later
        }
        if ($r['status'] === 'accepted') {
            $this->publish($run->user_id, ContentAssembler::missionsBundle($content['roadmap'], [$r['mission']['front']['id'] => $r['mission']]), [$r['artifact_id']]);
        }
    }

    /** Topics that could not be made are tried again, a few times in all. Returns how many were queued. */
    public function backfill(): int
    {
        $queued = 0;
        $paths = DB::select("SELECT r.id, a.content FROM generation_runs r JOIN generated_artifacts a ON a.run_id = r.id AND a.kind = 'roadmap_plan' AND a.published_release_id IS NOT NULL
                             WHERE r.kind = 'roadmap' AND r.status = 'accepted' AND r.created_at > ?", [Clock::now()->subDays(14)]);
        foreach ($paths as $p) {
            foreach (json_decode($p->content, true)['plan']['topics'] as $t) {
                if ($this->hasMission($p->id, $t['key'])) {
                    continue;
                }
                $attempts = DB::selectOne("SELECT count(*) AS n, max(created_at) AS last FROM generation_runs WHERE parent_run_id = ? AND kind = 'mission' AND params->>'topic_key' = ?", [$p->id, $t['key']]);
                if ((int) $attempts->n >= self::MAX_ATTEMPTS_PER_TOPIC || ($attempts->last && Clock::now()->subMinutes(30)->lt($attempts->last))) {
                    continue;
                }
                dispatch(new DraftExploreMission($p->id, $t['key']));
                $queued++;

                break; // one topic per path per pass, in order, so a struggling path cannot spend the budget
            }
        }

        return $queued;
    }

    /** @return array<string, mixed> */
    public function status(Learner $learner, string $runId): array
    {
        $run = DB::selectOne("SELECT * FROM generation_runs WHERE id = ? AND user_id = ? AND kind = 'roadmap'", [$runId, $learner->id]);
        if (! $run) {
            throw ApiException::notFound('That path');
        }
        $out = ['id' => $run->id, 'status' => 'working', 'stage' => $run->status === 'queued' ? 'queued' : $run->stage];

        if ($run->status === 'accepted') {
            $plan = DB::selectOne("SELECT content FROM generated_artifacts WHERE run_id = ? AND kind = 'roadmap_plan'", [$runId]);
            $content = json_decode($plan->content, true);
            $slug = $content['roadmap']['slug'];
            $ready = (int) DB::selectOne("SELECT count(*) AS n FROM generated_artifacts a JOIN generation_runs m ON m.id = a.run_id WHERE m.parent_run_id = ? AND a.kind = 'mission' AND a.published_release_id IS NOT NULL", [$runId])->n;
            $held = DB::selectOne("SELECT e.id FROM roadmap_enrolments e JOIN roadmaps r ON r.id = e.roadmap_id WHERE e.user_id = ? AND r.slug = ? AND e.status IN ('active', 'paused')", [$learner->id, $slug]);
            $out = ['id' => $run->id, 'status' => 'ready', 'stage' => 'ready', 'title' => $content['plan']['title'], 'outcome' => $content['plan']['outcome'],
                'topics' => array_map(fn ($t) => ['title' => $t['title'], 'minutes' => $t['minutes']], $content['plan']['topics']),
                'ready_count' => $ready, 'enrolment_id' => $held->id ?? null, 'coverage_note' => $content['roadmap']['roadmap']['versions'][1]['coverage_note']];
        } elseif (in_array($run->status, ['rejected', 'failed'], true)) {
            $detail = json_decode($run->reason_detail ?? '{}', true) ?: [];
            $out = ['id' => $run->id, 'status' => $run->status === 'rejected' ? 'rejected' : 'failed', 'stage' => $run->status, 'reason' => $run->reason_code,
                'message' => (string) ($detail['message'] ?? 'We could not create that path right now. Nothing was changed. Please try again later.')];
        }

        return $out;
    }

    /** Start the path: enrol and make it the focus. The path that was in focus is kept as it was. */
    public function start(Learner $learner, string $runId): array
    {
        $status = $this->status($learner, $runId);
        if ($status['status'] !== 'ready') {
            throw ApiException::conflict('explore_not_ready', 'That path is not ready yet.');
        }
        $slug = json_decode((string) DB::scalar("SELECT content FROM generated_artifacts WHERE run_id = ? AND kind = 'roadmap_plan'", [$runId]), true)['roadmap']['slug'];
        [$enrolment, $created] = $this->roadmaps->startPath($learner->id, $learner->subjectId, $slug, $learner->timeZone);
        if ($created) {
            EventRecorder::server('explore_started', $learner->subjectId, ['roadmap_enrolment_id' => $enrolment->id]);
        }

        return [$enrolment, $created];
    }

    /** @return list<array<string, mixed>> the learner's recent requests, newest first */
    public function recent(Learner $learner): array
    {
        $rows = DB::select("SELECT id, status FROM generation_runs WHERE user_id = ? AND kind = 'roadmap' ORDER BY created_at DESC LIMIT 5", [$learner->id]);

        return array_map(fn ($r) => $this->status($learner, $r->id), $rows);
    }

    // ---------------------------------------------------------------------------------------------------------------

    /** Validate once more, publish as an unreviewed generated release owned by this learner, and mark the artifacts published. */
    private function publish(string $userId, array $bundle, array $artifactIds): void
    {
        $errors = array_values(array_filter($this->validator->validate($bundle, ['allow_unreviewed' => true, 'allow_unsourced' => true, 'allow_partial' => true]), fn ($f) => $f['severity'] === 'error'));
        if ($errors !== []) {
            throw GenerationException::notGoodEnough($errors);
        }
        $stats = $this->publisher->publish($bundle, 'generation', null, ['origin' => 'generated', 'review_status' => 'unreviewed', 'owner_user_id' => $userId]);
        DB::update('UPDATE generated_artifacts SET published_release_id = ? WHERE id IN ('.implode(',', array_fill(0, count($artifactIds), '?')).')', [$stats['release_id'], ...$artifactIds]);
    }

    private function hasMission(string $runId, string $topicKey): bool
    {
        return (bool) DB::selectOne(
            "SELECT 1 FROM generated_artifacts WHERE kind = 'mission' AND published_release_id IS NOT NULL AND content_key = ?",
            [ContentAssembler::area($runId).'.'.$topicKey],
        );
    }

    private function failed(string $runId, ?string $userId, GenerationException $e): void
    {
        DB::update("UPDATE generation_runs SET status = CASE WHEN ? IN ('topic_rejected', 'quality') THEN 'rejected' ELSE 'failed' END, stage = 'done', reason_code = ?, reason_detail = ?::jsonb, finished_at = ? WHERE id = ? AND status IN ('queued', 'running')",
            [$e->code_, $e->code_, json_encode($e->detail + ['message' => $e->getMessage()]), Clock::now(), $runId]);
        $reason = match (true) {
            in_array($e->code_, ['topic_rejected', 'quality', 'unavailable'], true) => $e->code_,
            str_starts_with($e->code_, 'budget') => 'budget',
            default => 'other',
        };
        $this->emit($userId, 'explore_failed', ['reason' => $reason]);
    }

    private function emit(?string $userId, string $event, array $props): void
    {
        if ($userId === null) {
            return;
        }
        $subject = DB::scalar('SELECT analytics_subject_id FROM users WHERE id = ?', [$userId]);
        if ($subject) {
            EventRecorder::server($event, (string) $subject, $props);
        }
    }
}
