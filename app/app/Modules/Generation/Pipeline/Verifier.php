<?php

namespace App\Modules\Generation\Pipeline;

use App\Modules\Generation\Llm\LlmRequest;
use App\Modules\Generation\Llm\LlmResponse;
use App\Modules\Generation\ProviderManager;
use App\Modules\Generation\RunTally;
use App\Modules\Generation\Support\JsonShape;
use App\Modules\Generation\Support\Schemas;

/**
 * A second opinion that does not trust the author (docs/16 §5.3). Two calls, preferably to a different model:
 *   solve     takes the quiz blind. Wherever it disagrees with the key, or finds a question ambiguous, the item is flagged.
 *   critique  reads the whole mission with its key and lists factual problems, giveaway hints and unfair items.
 * Agreement between two models is evidence, not proof: that is why generated content stays labelled and capped.
 */
final class Verifier
{
    public function __construct(private ProviderManager $providers) {}

    /**
     * @param  array{front:array, sections:array, items:array}  $mission
     * @param  array{provider:string, model:string}  $drafter  Who wrote it, so a different model can be preferred
     * @return array{ok:bool, independent:bool, issues:list<array<string,string>>, notes:list<string>, solver:array{provider:string,model:string}, critic:array{provider:string,model:string}, answers:list<array<string,mixed>>}
     */
    public function verify(array $mission, string $topicTitle, array $drafter, RunTally $tally): array
    {
        $avoid = [$drafter['provider'].'|'.$drafter['model']];
        $issues = [];
        $notes = [];

        // 1. Blind solve of every auto-scored item.
        $quiz = $this->quiz($mission);
        $solveReq = new LlmRequest(
            task: 'solve',
            system: Prompts::solveSystem(),
            messages: [['role' => 'user', 'content' => $this->solveUser($mission, $quiz)]],
            schema: Schemas::solve(),
            schemaName: 'answers',
            temperature: 0.0,
            maxTokens: 3000,
            meta: ['topic' => $topicTitle, 'items' => array_map(fn ($q) => ['slot' => $q['slot'], 'type' => $q['type'], 'option_keys' => array_column($q['options'], 'key')], $quiz)],
        );
        $slots = array_column($quiz, 'slot');
        $solved = $this->providers->complete('verify', $solveReq, $tally, function (LlmResponse $r) use ($slots) {
            if ($r->json === null) {
                return ['the reply is not a JSON object'];
            }
            $p = JsonShape::check($r->json, Schemas::solve());
            $got = array_column($r->json['answers'] ?? [], 'slot');
            foreach (array_diff($slots, $got) as $missing) {
                $p[] = "no answer for {$missing}";
            }

            return $p;
        }, $avoid);

        $min = (float) config('generation.solve_min_confidence');
        $answers = [];
        foreach ($solved->json['answers'] as $a) {
            $answers[$a['slot']] = $a;
        }
        foreach ($quiz as $q) {
            $a = $answers[$q['slot']] ?? null;
            if ($a === null) {
                continue;
            }
            $key = $q['key'];
            $mine = $q['type'] === 'ordering' ? $a['order'] : $a['chosen'];
            $same = $q['type'] === 'ordering' ? $mine === $key : (count($mine) === count($key) && ! array_diff($mine, $key));
            if (! $same) {
                $issues[] = ['slot' => $q['slot'], 'severity' => 'blocker', 'text' => 'An independent solver, shown no key, chose '.json_encode(array_values($mine)).' but the key says '.json_encode(array_values($key)).'. Check the key, and whether another option is also defensible; make exactly one answer clearly best.'];
            } elseif ($a['ambiguous'] || (float) $a['confidence'] < $min) {
                $issues[] = ['slot' => $q['slot'], 'severity' => 'blocker', 'text' => 'The solver found this question ambiguous or was unsure ('.trim((string) $a['note']).'). Make the question and the options unambiguous.'];
            }
        }

        // 2. A critic reads everything, key included.
        $critReq = new LlmRequest(
            task: 'critique',
            system: Prompts::critiqueSystem(),
            messages: [['role' => 'user', 'content' => $this->critiqueUser($mission)]],
            schema: Schemas::critique(),
            schemaName: 'review',
            temperature: 0.0,
            maxTokens: 3000,
            meta: ['topic' => $topicTitle],
        );
        $critic = $this->providers->complete('verify', $critReq, $tally, fn (LlmResponse $r) => $r->json === null ? ['the reply is not a JSON object'] : JsonShape::check($r->json, Schemas::critique()), $avoid);
        $review = $critic->json;
        foreach ($review['issues'] as $i) {
            if ($i['severity'] === 'blocker') {
                $issues[] = ['slot' => (string) $i['slot'], 'severity' => 'blocker', 'text' => (string) $i['text']];
            } else {
                $notes[] = trim(($i['slot'] !== '' ? "[{$i['slot']}] " : '').$i['text']);
            }
        }
        if ($review['verdict'] !== 'pass') {
            foreach ($review['unsupported_claims'] as $c) {
                $issues[] = ['slot' => '', 'severity' => 'blocker', 'text' => "A claim a reviewer could not vouch for: {$c}. Remove it or turn it into reasoning from what the scenario shows."];
            }
            if ($review['verdict'] === 'fail' && $issues === []) {
                $issues[] = ['slot' => '', 'severity' => 'blocker', 'text' => 'The reviewer judged the mission unsound as a whole. Rewrite it more carefully.'];
            }
        } else {
            foreach ($review['unsupported_claims'] as $c) {
                $notes[] = "Unsupported claim (reviewer passed it): {$c}";
            }
        }

        $independent = ProviderManager::independent($drafter['provider'], $drafter['model'], $solved->provider, $solved->model)
            && ProviderManager::independent($drafter['provider'], $drafter['model'], $critic->provider, $critic->model);

        return [
            'ok' => $issues === [],
            'independent' => $independent,
            'issues' => $issues,
            'notes' => $notes,
            'solver' => ['provider' => $solved->provider, 'model' => $solved->model],
            'critic' => ['provider' => $critic->provider, 'model' => $critic->model],
            'answers' => array_values($answers),
        ];
    }

    /** The auto-scored items as a solver should see them: no key, no feedback, ordering options rotated. @return list<array<string,mixed>> */
    private function quiz(array $mission): array
    {
        $quiz = [];
        foreach ($mission['items'] as $item) {
            $slot = substr((string) $item['id'], strlen((string) $mission['front']['id']) + 1);
            $type = $item['type'];
            if ($type === 'self_check') {
                continue;
            }
            $options = $item['options'];
            $key = $type === 'ordering' ? $item['answer_order'] : array_values(array_map(fn ($o) => $o['key'], array_filter($options, fn ($o) => ! empty($o['correct']))));
            if ($type === 'ordering' && count($options) > 1) {
                $options = array_merge(array_slice($options, 1), array_slice($options, 0, 1)); // not shown in answer order
            }
            $quiz[] = ['slot' => $slot, 'type' => $type, 'scenario' => $item['scenario'] ?? '', 'prompt' => $item['prompt'], 'options' => $options, 'key' => $key];
        }

        return $quiz;
    }

    private function solveUser(array $mission, array $quiz): string
    {
        $out = "Teaching scenario:\n".$mission['sections']['scenario']['body']."\n\nWorked example:\n".$mission['sections']['worked-example']['body']."\n\nItems:\n";
        foreach ($quiz as $q) {
            $out .= "\n[{$q['slot']}] type: {$q['type']}\n";
            if ($q['scenario'] !== '') {
                $out .= "Situation: {$q['scenario']}\n";
            }
            $out .= "Question: {$q['prompt']}\n";
            foreach ($q['options'] as $o) {
                $out .= "  {$o['key']}) {$o['text']}\n";
            }
        }

        return $out;
    }

    private function critiqueUser(array $mission): string
    {
        $copy = $mission;
        unset($copy['front']['ai_assisted'], $copy['_dir']);

        return "The mission, with its answer key (options marked correct: true, or answer_order):\n".json_encode(['front' => ['title' => $copy['front']['title'], 'objective' => $copy['front']['objective']], 'sections' => $copy['sections'], 'items' => $copy['items']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
