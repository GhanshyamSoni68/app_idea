<?php

namespace App\Modules\Generation\Support;

/**
 * The JSON shapes the engine asks models for. They stay inside the subset every vendor's structured output accepts:
 * object/array/string/integer/number/boolean, enum, required (everything), additionalProperties:false. Limits such as
 * lengths and counts are enforced by the quality gates, not here, because several vendors reject them in a schema.
 */
final class Schemas
{
    public const SLOTS = ['primary-1', 'primary-2', 'changed-condition', 'check-1', 'alt-a', 'alt-b'];

    public const ITEM_TYPES = ['single_choice', 'multi_select', 'ordering', 'self_check'];

    private static function obj(array $props): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($props), 'properties' => $props];
    }

    private static function list(array $items): array
    {
        return ['type' => 'array', 'items' => $items];
    }

    private static function s(): array
    {
        return ['type' => 'string'];
    }

    /** The roadmap outline for a learner-chosen topic. */
    public static function plan(): array
    {
        return self::obj([
            'in_scope' => ['type' => 'boolean'],
            'scope_reason' => self::s(),
            'title' => self::s(),
            'outcome' => self::s(),
            'area' => self::s(),
            'topics' => self::list(self::obj([
                'key' => self::s(),
                'title' => self::s(),
                'skill_title' => self::s(),
                'objective' => self::s(),
                'minutes' => ['type' => 'integer'],
                'prerequisites' => self::list(self::s()),
                'misconceptions' => self::list(self::obj(['name' => self::s(), 'note' => self::s()])),
            ])),
        ]);
    }

    /** One mission: teaching text plus the six items the engine needs (docs/06 §3). */
    public static function draft(): array
    {
        $option = self::obj(['key' => self::s(), 'text' => self::s(), 'correct' => ['type' => 'boolean'], 'feedback' => self::s(), 'misconception' => self::s()]);
        $criterion = self::obj(['id' => self::s(), 'weight' => ['type' => 'string', 'enum' => ['essential', 'supporting']], 'text' => self::s()]);
        $item = self::obj([
            'slot' => ['type' => 'string', 'enum' => self::SLOTS],
            'type' => ['type' => 'string', 'enum' => self::ITEM_TYPES],
            'scenario_md' => self::s(),
            'prompt' => self::s(),
            'options' => self::list($option),
            'answer_order' => self::list(self::s()),
            'feedback_correct' => self::s(),
            'feedback_incorrect' => self::s(),
            'rubric' => self::list($criterion),
            'exemplar' => self::s(),
            'hints' => self::list(self::s()),
            'reveal' => self::s(),
            'time_estimate_minutes' => ['type' => 'number'],
        ]);

        return self::obj([
            'objective' => self::s(),
            'purpose' => self::s(),
            'scenario_md' => self::s(),
            'scenario_short_md' => self::s(),
            'worked_example_md' => self::s(),
            'misconceptions' => self::list(self::obj(['name' => self::s(), 'note' => self::s()])),
            'items' => self::list($item),
        ]);
    }

    /** A blind solve of every auto-scored item. */
    public static function solve(): array
    {
        return self::obj(['answers' => self::list(self::obj([
            'slot' => self::s(),
            'chosen' => self::list(self::s()),
            'order' => self::list(self::s()),
            'confidence' => ['type' => 'number'],
            'ambiguous' => ['type' => 'boolean'],
            'note' => self::s(),
        ]))]);
    }

    /** A critic's review of the whole mission. */
    public static function critique(): array
    {
        return self::obj([
            'verdict' => ['type' => 'string', 'enum' => ['pass', 'revise', 'fail']],
            'issues' => self::list(self::obj(['slot' => self::s(), 'severity' => ['type' => 'string', 'enum' => ['blocker', 'minor']], 'text' => self::s()])),
            'unsupported_claims' => self::list(self::s()),
        ]);
    }
}
