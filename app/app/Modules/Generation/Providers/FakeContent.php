<?php

namespace App\Modules\Generation\Providers;

/**
 * Deterministic stand-in content for tests and demos. It is real enough to pass the gates (so the pipeline can be
 * exercised end to end offline) and keyed by a hash so the fake "solver" can recompute the fake "author's" answers.
 * It is not teaching content. FakeProvider refuses to run in production.
 */
final class FakeContent
{
    public static function plan(string $topic, int $count = 4): array
    {
        $t = trim($topic);
        $stems = [
            ['foundations', 'Foundations of %s', 'Name the core parts of %s and what each one is for.'],
            ['first-uses', 'First practical uses of %s', 'Choose a sensible first use of %s for a small, realistic task.'],
            ['common-mistakes', 'Common mistakes with %s', 'Spot a common mistake when using %s and say why it happens.'],
            ['trade-offs', 'Trade-offs when using %s', 'Weigh one trade-off when using %s and justify a choice.'],
            ['debugging', 'Finding problems in %s', 'Pick the first thing to check when %s does not behave as expected.'],
            ['review', 'Reviewing work that uses %s', 'Review a short example that uses %s and list what to change.'],
        ];
        $topics = [];
        foreach (array_slice($stems, 0, max(3, min(6, $count))) as $i => [$key, $title, $objective]) {
            $topics[] = [
                'key' => $key,
                'title' => sprintf($title, $t),
                'skill_title' => sprintf($title, $t),
                'objective' => 'Given a short, fictional situation, '.lcfirst(sprintf($objective, $t)),
                'minutes' => 8,
                'prerequisites' => $i === 0 ? [] : [$stems[$i - 1][0]],
                'misconceptions' => [
                    ['name' => 'Skipping the basics', 'note' => 'It feels faster to start with the advanced parts, but the basics decide whether the advanced parts make sense.'],
                    ['name' => 'Changing many things at once', 'note' => 'When several things change together it is hard to know which one helped, so change one thing and compare.'],
                ],
            ];
        }

        return [
            'in_scope' => true,
            'scope_reason' => 'A software skill that can be practised with short scenarios.',
            'title' => 'Practical '.$t,
            'outcome' => 'Practise, and be checked on, the core decisions involved in '.$t.' using short fictional scenarios.',
            'area' => 'topic',
            'topics' => $topics,
        ];
    }

    /** Which option keys are correct for a slot. Same function for the author and the solver. */
    public static function correct(string $topic, string $slot, string $type, array $keys): array
    {
        $h = crc32($topic.'|'.$slot);
        if ($type === 'multi_select') {
            $a = $h % count($keys);

            return array_values(array_unique([$keys[$a], $keys[($a + 2) % count($keys)]]));
        }
        if ($type === 'ordering') {
            sort($keys); // the fake author lists steps a, b, c, d in the right order; the verifier shows them rotated

            return $keys;
        }

        return [$keys[$h % count($keys)]];
    }

    public static function draft(string $topic, string $objective, array $misconceptions): array
    {
        $scenarios = [
            'teach' => "A small team keeps running into trouble with {$topic}. Nobody wrote down what they tried, so each person starts again from a different idea. Every detail here is fictional.",
            'short' => "The team's work on {$topic} is stuck again. They have two ideas and time for one.",
            'primary-2' => "A second team uses {$topic} in a scheduling tool. A new colleague asks what to look at before changing anything.",
            'changed-condition' => "Suppose the same work happens with far fewer users and much less data than before, so some earlier assumptions no longer hold.",
            'check-1' => "A support portal built by another team shows slow, inconsistent behaviour tied to {$topic}. The team needs a first step they can defend.",
            'alt-a' => "An inventory app for a warehouse relies on {$topic}. Last week's change made things worse, and the notes are incomplete.",
            'alt-b' => "A reporting job in a billing service depends on {$topic}. It succeeds on most days and fails on a few, with no obvious pattern.",
        ];
        $mk = static function (string $slot, string $type, ?string $scenario, string $prompt) use ($topic, $misconceptions): array {
            $base = [
                'slot' => $slot, 'type' => $type, 'scenario_md' => $scenario ?? '', 'prompt' => $prompt,
                'options' => [], 'answer_order' => [], 'feedback_correct' => '', 'feedback_incorrect' => '',
                'rubric' => [], 'exemplar' => '', 'hints' => ['Think about what you could check before you change anything.', 'Compare the choices by what each one tells you.'],
                'reveal' => 'The best choice is the one that gathers evidence before changing anything.', 'time_estimate_minutes' => $type === 'self_check' ? 2 : 1,
            ];
            if ($type === 'single_choice' || $type === 'multi_select') {
                $keys = ['a', 'b', 'c', 'd'];
                $good = self::correct($topic, $slot, $type, $keys);
                $texts = ['a' => 'Collect evidence about it, then change one thing and compare.', 'b' => 'Replace the whole approach right away and hope that this helps.', 'c' => 'Leave it alone for a while to see whether it improves unaided.', 'd' => 'Ask the most senior person to decide without examining anything first.'];
                foreach ($keys as $k) {
                    $isGood = in_array($k, $good, true);
                    $base['options'][] = [
                        'key' => $k, 'text' => $texts[$k], 'correct' => $isGood,
                        'feedback' => $isGood
                            ? 'Yes. This gives you evidence to work from, so the next step is a decision and not a guess.'
                            : 'Not this one. It skips the evidence, so you cannot tell whether the change helped or hurt.',
                        'misconception' => $isGood ? '' : ($misconceptions[0]['name'] ?? ''),
                    ];
                }
            } elseif ($type === 'ordering') {
                $keys = ['a', 'b', 'c', 'd'];
                $steps = ['Describe what is going wrong in one sentence.', 'Collect evidence about when it happens.', 'Form one guess about the cause.', 'Change one thing and compare the results.'];
                foreach ($keys as $i => $k) {
                    $base['options'][] = ['key' => $k, 'text' => $steps[$i], 'correct' => false, 'feedback' => '', 'misconception' => ''];
                }
                $base['answer_order'] = $keys;
                $base['feedback_correct'] = 'Yes. Each step makes the next one safer, and you can show why you made it.';
                $base['feedback_incorrect'] = 'The order matters here: describe, collect evidence, guess, then test one change at a time.';
            } else {
                $base['rubric'] = [
                    ['id' => 'names-evidence', 'weight' => 'essential', 'text' => 'Points to something specific that could be checked.'],
                    ['id' => 'one-change', 'weight' => 'supporting', 'text' => 'Changes one thing at a time and compares.'],
                ];
                $base['exemplar'] = "I would first look at what actually happens, then change one thing and compare, so I can say what made the difference in {$topic}.";
            }

            return $base;
        };

        return [
            'objective' => $objective,
            'purpose' => 'Practise the first moves of '.$topic.'.',
            'scenario_md' => $scenarios['teach'],
            'scenario_short_md' => $scenarios['short'],
            'worked_example_md' => "Start with what you can observe. Write down the symptom, then the evidence, then one guess about the cause.\n\nChange **one thing** and compare against the same measure. Several changes at once hide which one mattered.",
            'misconceptions' => $misconceptions,
            'items' => [
                $mk('primary-1', 'single_choice', null, 'What is the best first move?'),
                $mk('primary-2', 'multi_select', $scenarios['primary-2'], 'Which of these are sensible things to do first? Choose all that apply.'),
                $mk('changed-condition', 'single_choice', $scenarios['changed-condition'], 'With these changed conditions, what would you do first?'),
                $mk('check-1', 'single_choice', $scenarios['check-1'], 'Which first step can the team defend?'),
                $mk('alt-a', 'ordering', $scenarios['alt-a'], 'Put these steps in a sensible order.'),
                $mk('alt-b', 'self_check', $scenarios['alt-b'], 'In two or three sentences, say what you would do first and why.'),
            ],
        ];
    }
}
