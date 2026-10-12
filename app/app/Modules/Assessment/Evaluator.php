<?php

namespace App\Modules\Assessment;

use App\Support\ApiException;

/**
 * Stateless scoring of one answer against an authored item (docs/05 §5.2). It never touches the
 * database, so the guest endpoint can use it without storing anything.
 */
final class Evaluator
{
    /**
     * @param  array<string, mixed>  $item  Item body from content_versions
     * @param  array<string, mixed>  $answer  By item type; see below
     * @param  array<string, string>  $misconceptions  id => note
     * @return array{outcome:string, score:float|null, basis:string, feedback:array<string, mixed>}
     */
    public function evaluate(array $item, array $answer, array $misconceptions = []): array
    {
        return match ($item['type']) {
            'single_choice' => $this->singleChoice($item, $answer, $misconceptions),
            'multi_select' => $this->multiSelect($item, $answer, $misconceptions),
            'ordering' => $this->ordering($item, $answer, $misconceptions),
            'self_check' => $this->selfCheck($item, $answer),
            default => throw new ApiException('item_type_unsupported', 'This question type cannot be checked yet.', 422),
        };
    }

    private function singleChoice(array $item, array $answer, array $mc): array
    {
        $choice = $answer['choice'] ?? null;
        $options = array_column($item['options'], null, 'key');
        if (! is_string($choice) || ! isset($options[$choice])) {
            throw $this->invalid('Choose one of the options.');
        }
        $selected = $options[$choice];
        $correct = collect($item['options'])->firstWhere('correct', true);
        $isCorrect = ! empty($selected['correct']);

        return [
            'outcome' => $isCorrect ? 'correct' : 'incorrect',
            'score' => $isCorrect ? 1.0 : 0.0,
            'basis' => 'auto_scored',
            'feedback' => [
                'text' => $selected['feedback'],
                'correct_key' => $correct['key'],
                'model_answer' => $isCorrect ? null : $correct['feedback'],
                'misconception' => $this->misconception($selected['misconception'] ?? null, $mc),
            ],
        ];
    }

    private function multiSelect(array $item, array $answer, array $mc): array
    {
        $choices = $answer['choices'] ?? null;
        $options = array_column($item['options'], null, 'key');
        if (! is_array($choices) || $choices === [] || array_diff($choices, array_keys($options))) {
            throw $this->invalid('Choose at least one option.');
        }
        $choices = array_values(array_unique($choices));
        $key = array_column(array_filter($item['options'], fn ($o) => ! empty($o['correct'])), 'key');
        $hits = count(array_intersect($choices, $key));
        $wrong = count(array_diff($choices, $key));
        $exact = $hits === count($key) && $wrong === 0;
        $outcome = $exact ? 'correct' : ($hits > 0 ? 'partially_correct' : 'incorrect');
        $notes = [];
        $misc = null;
        foreach ($choices as $c) {
            $notes[] = $options[$c]['feedback'];
            $misc ??= $this->misconception($options[$c]['misconception'] ?? null, $mc);
        }

        return [
            'outcome' => $outcome,
            'score' => count($key) > 0 ? max(0.0, ($hits - $wrong) / count($key)) : 0.0,
            'basis' => 'auto_scored',
            'feedback' => ['text' => implode(' ', $notes), 'correct_keys' => $key, 'model_answer' => $exact ? null : implode(' ', array_map(fn ($k) => $options[$k]['feedback'], $key)), 'misconception' => $misc],
        ];
    }

    private function ordering(array $item, array $answer, array $mc): array
    {
        $order = $answer['order'] ?? null;
        $keys = array_column($item['options'], 'key');
        if (! is_array($order) || count($order) !== count($keys) || array_diff($order, $keys) || count(array_unique($order)) !== count($keys)) {
            throw $this->invalid('Place every item in the order you think is right.');
        }
        $expected = $item['answer_order'];
        $right = 0;
        foreach ($expected as $i => $k) {
            $right += (($order[$i] ?? null) === $k) ? 1 : 0;
        }
        $exact = $right === count($expected);
        $outcome = $exact ? 'correct' : ($right * 2 >= count($expected) ? 'partially_correct' : 'incorrect');
        $text = $exact ? $item['feedback_correct'] : $item['feedback_incorrect'];
        $texts = array_column($item['options'], 'text', 'key');

        return [
            'outcome' => $outcome,
            'score' => $right / count($expected),
            'basis' => 'auto_scored',
            'feedback' => [
                'text' => $text,
                'correct_order' => $exact ? null : array_map(fn ($k) => ['key' => $k, 'text' => $texts[$k]], $expected),
                'model_answer' => null,
                'misconception' => $exact ? null : $this->misconception($item['misconception'] ?? null, $mc),
            ],
        ];
    }

    /** `answer`: {text, ratings: {criterion_id: 0|1|2}} (docs/05 §5.2). Met only on the rubric rule. */
    private function selfCheck(array $item, array $answer): array
    {
        $text = $answer['text'] ?? null;
        $ratings = $answer['ratings'] ?? null;
        $min = (int) config('devstep.open_response_min_chars');
        if (! is_string($text) || mb_strlen(trim($text)) < $min) {
            throw new ApiException('response_too_short', "Write at least {$min} characters before comparing with the model answer.", 422);
        }
        if (mb_strlen($text) > 32768) {
            throw $this->invalid('That answer is too long.');
        }
        $rubric = $item['rubric'];
        if (! is_array($ratings)) {
            throw $this->invalid('Rate each criterion against the model answer.');
        }
        $essentialMet = true;
        $supportingTotal = 0;
        $supportingOk = 0;
        foreach ($rubric as $criterion) {
            $r = $ratings[$criterion['id']] ?? null;
            if (! is_int($r) || $r < 0 || $r > 2) {
                throw $this->invalid('Rate every criterion 0, 1 or 2.');
            }
            if ($criterion['weight'] === 'essential') {
                $essentialMet = $essentialMet && $r === 2;
            } else {
                $supportingTotal++;
                $supportingOk += $r >= 1 ? 1 : 0;
            }
        }
        $ratio = $supportingTotal === 0 ? 1.0 : $supportingOk / $supportingTotal;
        $met = $essentialMet && $ratio >= (float) config('devstep.selfcheck_supporting_ratio');

        return [
            'outcome' => $met ? 'self_met' : 'self_not_met',
            'score' => null,
            'basis' => 'self_assessed',
            'feedback' => [
                'text' => $met
                    ? 'You rated every essential point as clearly covered. This is your own judgement against the model answer, so it is labelled self-assessed.'
                    : 'Compare the first sentence of the model answer with yours. This is your own judgement, so it is labelled self-assessed.',
                'exemplar' => $item['exemplar'],
                'model_answer' => null,
                'misconception' => null,
            ],
        ];
    }

    private function misconception(?string $id, array $notes): ?array
    {
        return $id && isset($notes[$id]) ? ['id' => $id, 'note' => $notes[$id]] : null;
    }

    private function invalid(string $message): ApiException
    {
        return new ApiException('answer_invalid', $message, 422);
    }

    /** Is this outcome "met" in the docs' sense (correct or self_met)? */
    public static function isMet(string $outcome): bool
    {
        return in_array($outcome, ['correct', 'self_met'], true);
    }
}
