<?php

namespace App\Modules\Generation\Pipeline;

use App\Modules\Generation\Llm\LlmRequest;
use App\Modules\Generation\Llm\LlmResponse;
use App\Modules\Generation\ProviderManager;
use App\Modules\Generation\RunTally;
use App\Modules\Generation\Support\JsonShape;
use App\Modules\Generation\Support\Schemas;

/** One topic -> one mission draft (JSON), or a repaired draft when given the problems found in the last one. */
final class MissionDrafter
{
    public function __construct(private ProviderManager $providers) {}

    /**
     * @param  array<string, mixed>  $topic
     * @param  array{title:string, ...}  $plan
     * @param  array<string, mixed>|null  $previous  The last draft, when repairing
     * @param  list<array<string, string>>  $issues  What to fix, when repairing
     * @return array{draft: array<string, mixed>, response: LlmResponse}
     */
    public function draft(array $topic, array $plan, string $level, RunTally $tally, ?array $previous = null, array $issues = []): array
    {
        $user = Prompts::draftUser((string) $topic['title'], (string) $topic['objective'], (string) $topic['skill_title'], $topic['misconceptions'], $level, (string) $plan['title']);
        $messages = [['role' => 'user', 'content' => $user]];
        if ($previous !== null) {
            $messages = [['role' => 'user', 'content' => $user."\n\n".Prompts::repairUser(json_encode($previous), $issues)]];
        }
        $req = new LlmRequest(
            task: 'draft',
            system: Prompts::draftSystem(),
            messages: $messages,
            schema: Schemas::draft(),
            schemaName: 'mission',
            temperature: 0.5,
            maxTokens: 12000,
            meta: ['topic' => $topic['title'], 'objective' => $topic['objective'], 'misconceptions' => $topic['misconceptions']],
        );
        $response = $this->providers->complete('draft', $req, $tally, fn (LlmResponse $r) => self::problems($r->json));

        return ['draft' => $response->json, 'response' => $response];
    }

    /** @return list<string> */
    public static function problems(?array $draft): array
    {
        if ($draft === null) {
            return ['the reply is not a JSON object'];
        }
        $problems = JsonShape::check($draft, Schemas::draft());
        if ($problems !== []) {
            return $problems;
        }
        $slots = array_count_values(array_column($draft['items'], 'slot'));
        foreach (Schemas::SLOTS as $slot) {
            if (($slots[$slot] ?? 0) !== 1) {
                $problems[] = "there must be exactly one item for slot {$slot}";
            }
        }
        if ($problems !== []) {
            return $problems;
        }
        $variety = 0;
        foreach ($draft['items'] as $item) {
            $slot = $item['slot'];
            $type = $item['type'];
            if (($slot === 'alt-b') !== ($type === 'self_check')) {
                $problems[] = "{$slot}: only alt-b is a self_check item, and alt-b must be one";
            }
            $variety += in_array($type, ['multi_select', 'ordering'], true) ? 1 : 0;
            $keys = array_column($item['options'], 'key');
            if (in_array($type, ['single_choice', 'multi_select'], true)) {
                $good = count(array_filter($item['options'], fn ($o) => $o['correct']));
                if ($type === 'single_choice' && $good !== 1) {
                    $problems[] = "{$slot}: a single_choice item needs exactly one correct option, it has {$good}";
                }
                if ($type === 'multi_select' && ($good < 2 || $good >= count($keys))) {
                    $problems[] = "{$slot}: a multi_select item needs two or more correct options and at least one wrong one";
                }
            }
            if (in_array($type, ['single_choice', 'multi_select', 'ordering'], true) && (count($keys) !== count(array_unique($keys)) || in_array('', $keys, true))) {
                $problems[] = "{$slot}: option keys must be unique and not empty";
            }
            if ($type === 'ordering') {
                $order = $item['answer_order'];
                if (count($order) !== count($keys) || array_diff($keys, $order) || array_diff($order, $keys) || count(array_unique($order)) !== count($order)) {
                    $problems[] = "{$slot}: answer_order must list every option key exactly once";
                }
            }
            if ($type === 'self_check') {
                $essential = count(array_filter($item['rubric'], fn ($c) => $c['weight'] === 'essential'));
                if (count($item['rubric']) < 2 || $essential < 1 || trim($item['exemplar']) === '') {
                    $problems[] = "{$slot}: a self_check item needs a rubric of at least two criteria (one essential) and an exemplar";
                }
            }
            if (trim($item['prompt']) === '') {
                $problems[] = "{$slot}: the prompt is empty";
            }
        }
        if ($variety < 1) {
            $problems[] = 'use at least one multi_select or ordering item';
        }

        return $problems;
    }
}
