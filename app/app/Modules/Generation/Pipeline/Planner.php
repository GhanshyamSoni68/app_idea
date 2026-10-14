<?php

namespace App\Modules\Generation\Pipeline;

use App\Modules\Generation\GenerationException;
use App\Modules\Generation\Llm\LlmRequest;
use App\Modules\Generation\Llm\LlmResponse;
use App\Modules\Generation\ProviderManager;
use App\Modules\Generation\RunTally;
use App\Modules\Generation\Support\JsonShape;
use App\Modules\Generation\Support\Schemas;

/** Topic -> a small validated outline of skills. The model proposes; this class checks the outline is sound. */
final class Planner
{
    public function __construct(private ProviderManager $providers, private QualityGates $gates) {}

    /**
     * @return array{title:string, outcome:string, area:string, topics:list<array<string, mixed>>}
     *
     * @throws GenerationException topic_rejected when the planner says it is out of scope
     */
    public function plan(string $topic, string $level, int $minutes, RunTally $tally): array
    {
        $min = (int) config('generation.roadmap.min_topics');
        $max = (int) config('generation.roadmap.max_topics');
        $req = new LlmRequest(
            task: 'plan',
            system: Prompts::planSystem($min, $max),
            messages: [['role' => 'user', 'content' => Prompts::planUser($topic, $level, $minutes)]],
            schema: Schemas::plan(),
            schemaName: 'learning_path',
            temperature: 0.3,
            maxTokens: 4000,
            meta: ['topic' => $topic, 'topic_count' => $max],
        );

        $response = $this->providers->complete('plan', $req, $tally, fn (LlmResponse $r) => $this->problems($r->json, $min, $max));
        $plan = $response->json;

        if (! $plan['in_scope']) {
            throw GenerationException::rejectedTopic($this->plainReason($plan['scope_reason']));
        }
        $plan['area'] = strtolower($plan['area']);
        foreach ($plan['topics'] as &$t) {
            $t['minutes'] = max(5, min(12, (int) $t['minutes']));
            $t['prerequisites'] = array_values(array_unique($t['prerequisites']));
        }
        unset($t);

        // What the learner will read on the path screen passes the same text rules as the lessons.
        $texts = ['plan.title' => $plan['title'], 'plan.outcome' => $plan['outcome']];
        foreach ($plan['topics'] as $i => $t) {
            $texts["plan.topics[{$i}].title"] = $t['title'];
            $texts["plan.topics[{$i}].objective"] = $t['objective'];
        }
        $errors = QualityGates::errors($this->gates->checkTexts($texts));
        if ($errors !== []) {
            throw GenerationException::notGoodEnough($errors);
        }

        return $plan;
    }

    /** @return list<string> */
    private function problems(?array $plan, int $min, int $max): array
    {
        if ($plan === null) {
            return ['the reply is not a JSON object'];
        }
        $problems = JsonShape::check($plan, Schemas::plan());
        if ($problems !== [] || ! $plan['in_scope']) {
            return $problems;
        }
        $n = count($plan['topics']);
        if ($n < $min || $n > $max) {
            $problems[] = "topics must have {$min} to {$max} entries, it has {$n}";
        }
        if (! preg_match('/^[a-z]{2,10}$/', strtolower((string) $plan['area']))) {
            $problems[] = 'area must be one short lowercase word of letters only';
        }
        if (trim($plan['title']) === '' || trim($plan['outcome']) === '') {
            $problems[] = 'title and outcome must not be empty';
        }
        $seen = [];
        foreach ($plan['topics'] as $i => $t) {
            $k = $t['key'];
            if (! preg_match('/^[a-z][a-z0-9-]{1,40}$/', $k)) {
                $problems[] = "topics[{$i}].key must be lowercase letters, digits and hyphens";
            }
            if (isset($seen[$k])) {
                $problems[] = "topics[{$i}].key \"{$k}\" is repeated";
            }
            foreach (['title', 'skill_title', 'objective'] as $f) {
                if (trim((string) $t[$f]) === '') {
                    $problems[] = "topics[{$i}].{$f} must not be empty";
                }
            }
            foreach ($t['prerequisites'] as $p) {
                if (! isset($seen[$p])) {
                    $problems[] = "topics[{$i}].prerequisites names \"{$p}\", which is not an earlier topic";
                }
            }
            $seen[$k] = true;
        }

        return $problems;
    }

    /** A model's refusal reason is shown to the learner, so it is trimmed to one plain sentence. */
    private function plainReason(string $reason): string
    {
        $reason = trim(preg_replace('/\s+/', ' ', strip_tags($reason)) ?? '');
        $first = preg_split('/(?<=[.!?])\s/', $reason)[0] ?? '';

        return $first !== '' && mb_strlen($first) <= 220
            ? $first.' Try a topic about a software skill.'
            : 'That topic does not fit what we can build a practice path for. Try a topic about a software skill.';
    }
}
