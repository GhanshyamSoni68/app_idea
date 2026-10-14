<?php

namespace App\Modules\Generation;

use App\Modules\Catalogue\ContentValidator;
use App\Modules\Generation\Pipeline\ContentAssembler;
use App\Modules\Generation\Pipeline\MissionDrafter;
use App\Modules\Generation\Pipeline\Planner;
use App\Modules\Generation\Pipeline\QualityGates;
use App\Modules\Generation\Pipeline\TopicGuard;
use App\Modules\Generation\Pipeline\Verifier;
use App\Support\Clock;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * The content engine's one entry point (docs/16 §3).
 *
 *   planRoadmap   topic -> guard -> plan -> a stored, validated outline (run kind "roadmap")
 *   draftMission  one outline topic -> draft -> gates -> blind solve + critique -> repair loop -> a stored mission (run kind "mission")
 *
 * Nothing here publishes. Accepted content is stored as a quarantined artifact; making it learner-visible is a separate,
 * labelled step (docs/16 §7). Every call, cost and decision is logged.
 */
final class GenerationService
{
    public function __construct(
        private ProviderManager $providers,
        private GenerationLog $log,
        private QualityGates $gates,
        private Planner $planner,
        private MissionDrafter $drafter,
        private Verifier $verifier,
        private ContentValidator $validator,
    ) {}

    public static function make(?ProviderManager $providers = null): self
    {
        $providers ??= new ProviderManager;
        $log = new GenerationLog;
        $gates = new QualityGates;

        return new self($providers, $log, $gates, new Planner($providers, $gates), new MissionDrafter($providers), new Verifier($providers), new ContentValidator);
    }

    /**
     * @param  array{level?:string, minutes?:int}  $opts
     * @return array{run_id:string, artifact_id:string, plan:array<string, mixed>, roadmap:array<string, mixed>}
     *
     * @throws GenerationException
     */
    public function planRoadmap(?string $userId, string $rawTopic, array $opts = [], ?string $runId = null): array
    {
        if (! config('generation.enabled')) {
            throw GenerationException::disabled();
        }
        $level = $opts['level'] ?? 'some experience';
        $minutes = (int) ($opts['minutes'] ?? 10);

        $topic = TopicGuard::normalise($rawTopic); // refused before a run exists: nothing stored, nothing spent
        if ($runId === null && $userId !== null && $this->log->userRunsToday($userId) >= (int) config('generation.budget.per_user_daily_runs')) {
            throw GenerationException::budget('user');
        }

        $runId ??= $this->log->startRun('roadmap', $userId, $topic, ['level' => $level, 'minutes' => $minutes]);
        $tally = new RunTally($runId, (float) config('generation.budget.run_usd'));
        try {
            $this->log->stage($runId, 'plan');
            $plan = $this->planner->plan($topic, $level, $minutes, $tally);

            $assembler = new ContentAssembler(ContentAssembler::area($runId));
            $roadmap = $assembler->roadmap($plan);
            $bundle = ContentAssembler::bundle($roadmap, []);
            $findings = $this->validator->validate($bundle, ['allow_unreviewed' => true, 'allow_unsourced' => true, 'allow_partial' => true]);
            if (ContentValidator::hasErrors($findings)) {
                throw GenerationException::notGoodEnough(array_values(array_filter($findings, fn ($f) => $f['severity'] === 'error')));
            }

            $artifactId = $this->store($runId, $userId, 'roadmap_plan', $roadmap['slug'], ['plan' => $plan, 'roadmap' => $roadmap], ['warnings' => $findings]);
            $this->log->finish($runId, 'accepted', null, null, $tally);

            return ['run_id' => $runId, 'artifact_id' => $artifactId, 'plan' => $plan, 'roadmap' => $roadmap];
        } catch (GenerationException $e) {
            $this->fail($runId, $e, $tally);

            throw $e;
        }
    }

    /**
     * @param  array{plan:array<string,mixed>, roadmap:array<string,mixed>}  $planned  planRoadmap's result
     * @return array{run_id:string, status:string, artifact_id:?string, mission:?array<string,mixed>, rounds:int, independent:?bool, findings:array}
     */
    public function draftMission(?string $userId, string $parentRunId, array $planned, string $topicKey, array $opts = []): array
    {
        if (! config('generation.enabled')) {
            throw GenerationException::disabled();
        }
        $plan = $planned['plan'];
        $topic = collect($plan['topics'])->firstWhere('key', $topicKey) ?? throw new \InvalidArgumentException("No topic \"{$topicKey}\" in this plan.");
        $level = $opts['level'] ?? 'some experience';
        $area = ContentAssembler::area($parentRunId);
        $assembler = new ContentAssembler($area);

        $runId = $this->log->startRun('mission', $userId, (string) $topic['title'], ['topic_key' => $topicKey, 'level' => $level], $parentRunId);
        $tally = new RunTally($runId, (float) config('generation.budget.run_usd'));
        $rounds = 0;
        $previous = null;
        $issues = [];
        $lastFindings = [];
        $maxRounds = (int) config('generation.max_repair_rounds');

        try {
            for ($round = 0; $round <= $maxRounds; $round++) {
                $rounds = $round;
                $this->log->stage($runId, $round === 0 ? 'draft' : "repair-{$round}");
                $out = $this->drafter->draft($topic, $plan, $level, $tally, $previous, $issues);
                $drafted = ['provider' => $out['response']->provider, 'model' => $out['response']->model];
                $mission = $assembler->mission($topic, $out['draft'], "{$drafted['provider']}/{$drafted['model']}");

                // Gate 1: deterministic rules and the existing content validator. No model involved.
                $this->log->stage($runId, 'gates');
                $findings = $this->gates->check($mission);
                $bundle = ['skills' => [$mission['front']['id'] => ['id' => $mission['front']['id'], 'title' => $topic['skill_title']]], 'skill_prerequisites' => [], 'roadmaps' => [], 'missions' => [$mission['front']['id'] => $mission], 'hash' => ''];
                foreach ($this->validator->validate($bundle, ['allow_unreviewed' => true, 'allow_unsourced' => true, 'allow_partial' => true]) as $f) {
                    $findings[] = $f;
                }
                $lastFindings = $findings;
                $errors = QualityGates::errors($findings);
                if ($errors !== []) {
                    $previous = $out['draft'];
                    $issues = array_map(fn ($f) => ['path' => $f['path'], 'message' => "{$f['code']}: {$f['message']}"], array_slice($errors, 0, 20));

                    continue;
                }

                // Gate 2: a second opinion, preferably from a different model.
                $this->log->stage($runId, 'verify');
                $verdict = $this->verifier->verify($mission, (string) $topic['title'], $drafted, $tally);
                if (config('generation.require_independent_verifier') && ! $verdict['independent']) {
                    throw new GenerationException('verifier_not_independent', 'We could not check that path independently, so we did not keep it. Please try again later.', ['verifier' => [$verdict['solver'], $verdict['critic']], 'drafter' => $drafted]);
                }
                if (! $verdict['ok']) {
                    $previous = $out['draft'];
                    $issues = $verdict['issues'];
                    $lastFindings = array_merge($findings, array_map(fn ($i) => ['severity' => 'error', 'code' => 'V-solve', 'path' => $i['slot'], 'message' => $i['text']], $verdict['issues']));

                    continue;
                }

                $quality = [
                    'warnings' => array_values(array_filter($findings, fn ($f) => $f['severity'] === 'warning')),
                    'verification' => ['independent' => $verdict['independent'], 'solver' => $verdict['solver'], 'critic' => $verdict['critic'], 'notes' => $verdict['notes'], 'answers' => $verdict['answers']],
                    'drafted_by' => $drafted, 'repair_rounds' => $round, 'prompt_version' => config('generation.prompt_version'),
                    'tier' => $verdict['independent'] ? 'verified_independent' : 'verified_same_model',
                ];
                $artifactId = $this->store($runId, $userId, 'mission', $mission['front']['id'], $mission, $quality);
                $this->log->finish($runId, 'accepted', null, null, $tally, $round, $verdict['independent']);

                return ['run_id' => $runId, 'status' => 'accepted', 'artifact_id' => $artifactId, 'mission' => $mission, 'rounds' => $round, 'independent' => $verdict['independent'], 'findings' => $quality['warnings']];
            }

            // Out of repair rounds: nothing is kept.
            $e = GenerationException::notGoodEnough(array_slice(QualityGates::errors($lastFindings), 0, 12));
            $this->log->finish($runId, 'rejected', 'quality', $e->detail, $tally, $rounds);

            return ['run_id' => $runId, 'status' => 'rejected', 'artifact_id' => null, 'mission' => null, 'rounds' => $rounds, 'independent' => null, 'findings' => $lastFindings];
        } catch (GenerationException $e) {
            $this->fail($runId, $e, $tally, $rounds);

            throw $e;
        }
    }

    /** Keep the run record honest: a refusal of the topic is "rejected", everything else that stopped it is "failed". */
    private function fail(string $runId, GenerationException $e, RunTally $tally, int $rounds = 0): void
    {
        $status = in_array($e->code_, ['topic_rejected', 'quality'], true) ? 'rejected' : 'failed';
        $this->log->finish($runId, $status, $e->code_, $e->detail === [] ? null : $e->detail, $tally, $rounds);
    }

    /** @param  array<string, mixed>  $content @param  array<string, mixed>  $quality */
    private function store(string $runId, ?string $userId, string $kind, string $key, array $content, array $quality): string
    {
        $id = Ids::new();
        DB::insert(
            'INSERT INTO generated_artifacts (id, run_id, owner_user_id, kind, content_key, content, content_hash, quality, status, created_at) VALUES (?, ?, ?, ?, ?, ?::jsonb, ?, ?::jsonb, \'quarantined\', ?)',
            [$id, $runId, $userId, $kind, $key, json_encode($content), hash('sha256', json_encode($content)), json_encode($quality), Clock::now()],
        );

        return $id;
    }
}
