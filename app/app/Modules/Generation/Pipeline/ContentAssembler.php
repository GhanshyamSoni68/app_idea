<?php

namespace App\Modules\Generation\Pipeline;

use App\Modules\Catalogue\ContentLoader;

/**
 * Builds the exact structure ContentLoader would have read from files (docs/06 §4), from the model's JSON. The model
 * never writes front matter, IDs, step lists or flags: those are decided here, so structure cannot drift whatever model
 * is behind the engine. The same validator, publisher and player then work on generated content unchanged.
 */
final class ContentAssembler
{
    /** One namespace per generation run keeps generated skill and mission IDs clear of authored ones and of each other. */
    public static function area(string $runId): string
    {
        return 'g'.substr(hash('sha256', $runId), 0, 8);
    }

    public static function slug(string $s): string
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)) ?? '', '-');

        return $slug === '' ? 'x' : substr($slug, 0, 40);
    }

    public function __construct(private string $area) {}

    public function missionId(string $topicKey): string
    {
        return "{$this->area}.{$topicKey}";
    }

    /**
     * @param  array<string, mixed>  $topic  One topic of the plan
     * @param  array<string, mixed>  $draft  The model's mission JSON (already shape-checked)
     * @return array{front: array, sections: array, items: array}
     */
    public function mission(array $topic, array $draft, string $draftedBy): array
    {
        $id = $this->missionId($topic['key']);
        $mc = [];
        foreach ($draft['misconceptions'] as $m) {
            $mc[mb_strtolower(trim((string) $m['name']))] = ['id' => "{$this->area}.mc.".self::slug((string) $m['name']), 'name' => trim((string) $m['name']), 'note' => trim((string) $m['note'])];
        }

        $bySlot = [];
        foreach ($draft['items'] as $item) {
            $bySlot[$item['slot']] = $item;
        }
        $items = [];
        foreach (['primary-1', 'primary-2', 'changed-condition', 'check-1', 'alt-a', 'alt-b'] as $slot) {
            $items["{$id}.{$slot}"] = $this->item($id, $topic['key'], $slot, $bySlot[$slot], $mc);
        }

        $practise = (int) round(array_sum(array_map(fn ($i) => (float) ($i['time_estimate_minutes'] ?? 1), array_filter($items, fn ($i) => $i['role'] === 'primary'))) + 3);
        $front = [
            'id' => $id,
            'version' => '1.0.0',
            'change_class' => 'minor',
            'status' => 'published',
            'title' => trim((string) $topic['title']),
            'purpose' => trim((string) $draft['purpose']) ?: trim((string) $topic['title']),
            'objective_key' => $id,
            'objective_major' => 1,
            'objective' => trim((string) $draft['objective']) ?: trim((string) $topic['objective']),
            'primary_skill' => $id,
            'secondary_skills' => [],
            'scenario_key' => "{$this->area}.sc.{$topic['key']}",
            'prerequisites' => ['missions' => array_map(fn ($p) => $this->missionId($p), $topic['prerequisites'])],
            'time_estimate_minutes' => ['practise' => max(6, min(14, $practise)), 'small' => 3],
            'steps' => [
                ['key' => 'scenario', 'kind' => 'scenario'],
                ['key' => 'first-move', 'kind' => 'response', 'item' => "{$id}.primary-1", 'modes' => ['practise', 'small'], 'stop_after' => true],
                ['key' => 'worked-example', 'kind' => 'explanation', 'stop_after' => true],
                ['key' => 'second-move', 'kind' => 'response', 'item' => "{$id}.primary-2"],
                ['key' => 'changed-condition', 'kind' => 'response', 'item' => "{$id}.changed-condition"],
                ['key' => 'scenario-short', 'kind' => 'scenario', 'modes' => ['small']],
            ],
            'small_variant' => ['steps' => ['scenario-short', 'first-move'], 'purpose' => trim((string) $draft['purpose']) ?: trim((string) $topic['title'])],
            'held_back' => ['topic_check' => ["{$id}.check-1"], 'alternates' => ["{$id}.alt-a", "{$id}.alt-b"]],
            'stack_scope' => ['portable_concept' => true],
            'volatility' => 'unknown',
            'sources' => [],
            'accessibility' => ['reviewed_by' => 'pending', 'reviewed_on' => null],
            'author' => 'ai-generated',
            'review' => ['reviewer' => 'pending', 'last_reviewed' => null, 'tried_exercise' => false, 'tried_minutes' => null],
            'ai_assisted' => ['used' => true, 'generated' => true, 'drafted_by' => $draftedBy, 'parts' => ['everything']],
        ];

        $sections = [
            'scenario' => ['heading' => 'Scenario', 'body' => trim((string) $draft['scenario_md'])],
            'worked-example' => ['heading' => 'Worked example', 'body' => trim((string) $draft['worked_example_md'])],
            'scenario-short' => ['heading' => 'Scenario (short)', 'body' => trim((string) $draft['scenario_short_md'])],
            'misconceptions' => ['heading' => 'Misconceptions', 'body' => implode("\n", array_map(fn ($m) => "- **{$m['id']}**: \"{$m['name']}.\" {$m['note']}", $mc))],
        ];

        return ['front' => $front, 'sections' => $sections, 'items' => $items, '_dir' => "generated/{$id}"];
    }

    /** @param  array<string, array{id:string,name:string,note:string}>  $mc */
    private function item(string $missionId, string $topicKey, string $slot, array $in, array $mc): array
    {
        $held = in_array($slot, ['check-1', 'alt-a', 'alt-b'], true);
        $type = $in['type'];
        $out = [
            'id' => "{$missionId}.{$slot}",
            'role' => match ($slot) { 'check-1' => 'topic_check', 'alt-a', 'alt-b' => 'alternate', default => 'primary' },
            'type' => $type,
            'evidence_basis' => $type === 'self_check' ? 'self_assessed' : 'auto_scored',
            'time_estimate_minutes' => $this->minutes((float) $in['time_estimate_minutes'], $held),
            'scenario_key' => $held ? "{$this->area}.sc.{$topicKey}-{$slot}" : "{$this->area}.sc.{$topicKey}",
        ];
        match ($slot) {
            'check-1' => $out['check_eligible'] = true,
            'alt-a' => $out += ['check_eligible' => true, 'changed_conditions_of' => "{$missionId}.primary-2"],
            'alt-b' => $out += ['retention_reserved' => true, 'changed_conditions_of' => "{$missionId}.primary-2"],
            default => null,
        };
        if (trim((string) $in['scenario_md']) !== '') {
            $out['scenario'] = trim((string) $in['scenario_md']);
        }
        $out['prompt'] = trim((string) $in['prompt']);

        if ($type === 'single_choice' || $type === 'multi_select') {
            $out['options'] = array_map(function ($o) use ($mc) {
                $opt = ['key' => (string) $o['key'], 'text' => trim((string) $o['text'])];
                if (! empty($o['correct'])) {
                    $opt['correct'] = true;
                } elseif (($m = $mc[mb_strtolower(trim((string) $o['misconception']))] ?? null) !== null) {
                    $opt['misconception'] = $m['id'];
                }
                $opt['feedback'] = trim((string) $o['feedback']);

                return $opt;
            }, $in['options']);
        } elseif ($type === 'ordering') {
            $out['options'] = array_map(fn ($o) => ['key' => (string) $o['key'], 'text' => trim((string) $o['text'])], $in['options']);
            $out['answer_order'] = array_values($in['answer_order']);
            $out['feedback_correct'] = trim((string) $in['feedback_correct']);
            $out['feedback_incorrect'] = trim((string) $in['feedback_incorrect']);
        } else {
            $out['rubric'] = array_map(fn ($c) => ['id' => self::slug((string) $c['id']), 'weight' => $c['weight'], 'text' => trim((string) $c['text'])], $in['rubric']);
            $out['exemplar'] = trim((string) $in['exemplar']);
        }
        $out['hints'] = array_values(array_map(fn ($h) => trim((string) $h), $in['hints']));
        $out['reveal'] = trim((string) $in['reveal']);

        return $out;
    }

    private function minutes(float $m, bool $held): float
    {
        return $held ? max(1.0, min(2.0, $m)) : max(1.0, min(3.0, $m));
    }

    /**
     * The path itself: skills, prerequisite edges and one roadmap version, in ContentLoader's shape.
     *
     * @param  array{title:string, outcome:string, area:string, topics:list<array<string, mixed>>}  $plan
     * @return array{slug:string, skills: array<string, array>, prerequisites: list<array{0:string,1:string}>, roadmap: array}
     */
    public function roadmap(array $plan): array
    {
        $slug = $this->area.'-'.self::slug($plan['title']);
        $skills = [];
        $edges = [];
        $topics = [];
        foreach ($plan['topics'] as $t) {
            $id = $this->missionId($t['key']);
            $skills[$id] = ['id' => $id, 'title' => trim((string) $t['skill_title'])];
            foreach ($t['prerequisites'] as $p) {
                $edges[] = [$this->missionId($p), $id];
            }
            $topics[] = [
                'id' => $id, 'title' => trim((string) $t['title']), 'kind' => 'required', 'minutes' => $t['minutes'], 'mission' => $id,
                'prerequisites' => array_map(fn ($p) => ['topic' => $this->missionId($p)], $t['prerequisites']),
            ];
        }
        $note = 'This path was created for you by software and checked automatically. A person has not reviewed it yet. Completing it shows you practised these topics; it does not show verified skill.';

        return [
            'slug' => $slug,
            'skills' => $skills,
            'prerequisites' => $edges,
            'roadmap' => [
                'meta' => ['slug' => $slug, 'title' => trim($plan['title']), 'outcome' => trim($plan['outcome'])],
                'versions' => [1 => [
                    'roadmap' => $slug, 'version' => 1, 'change_class' => 'initial', 'title' => trim($plan['title']), 'outcome' => trim($plan['outcome']),
                    'estimated_effort' => 'About '.count($plan['topics']).' short sessions', 'estimated_effort_hours' => max(1, (int) ceil(array_sum(array_column($plan['topics'], 'minutes')) / 60)),
                    'coverage_note' => $note,
                    'modules' => [['id' => $this->area, 'title' => trim($plan['title']), 'topics' => $topics]],
                ]],
                '_dir' => "generated/{$slug}",
            ],
        ];
    }

    /** Content in the shape ContentValidator and ContentPublisher take. */
    public static function bundle(array $roadmap, array $missions): array
    {
        $content = [
            'skills' => $roadmap['skills'],
            'skill_prerequisites' => $roadmap['prerequisites'],
            'roadmaps' => [$roadmap['slug'] => $roadmap['roadmap']],
            'missions' => $missions,
        ];
        $content['hash'] = hash('sha256', json_encode($content));

        return $content;
    }

    public static function loaderSlug(string $heading): string
    {
        return ContentLoader::slug($heading);
    }
}
