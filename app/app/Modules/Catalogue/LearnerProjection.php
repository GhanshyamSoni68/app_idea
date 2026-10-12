<?php

namespace App\Modules\Catalogue;

/**
 * What the client may see before submission (docs/02: the API never sends held-back items, answer keys,
 * feedback, hints or reveals before the learner submits). Hints and reveals are fetched one at a time
 * so the server can record them first.
 */
final class LearnerProjection
{
    /**
     * @param  list<object>  $items  Items from ContentRepository::missionItems()
     * @param  list<string>|null  $onlySteps  Restrict to these step keys (small variant)
     */
    public static function mission(object $mission, array $items, ?array $onlySteps = null): array
    {
        $front = $mission->body['front'];
        $sections = $mission->body['sections'];
        $byKey = [];
        foreach ($items as $it) {
            $byKey[$it->content_key] = $it;
        }

        $steps = [];
        foreach ($front['steps'] as $i => $step) {
            if ($onlySteps !== null && ! in_array($step['key'], $onlySteps, true)) {
                continue;
            }
            $section = $sections[$step['key']] ?? null;
            $item = null;
            if (($step['kind'] ?? '') === 'response') {
                $row = $byKey[$step['item']] ?? null;
                $item = $row ? self::item($row) : null;
            }
            $steps[] = [
                'key' => $step['key'],
                'kind' => $step['kind'],
                'title' => $section['heading'] ?? ucfirst(str_replace('-', ' ', $step['key'])),
                'body' => $section['body'] ?? '',
                'modes' => $step['modes'] ?? ['practise'],
                'stop_after' => (bool) ($step['stop_after'] ?? false),
                'item' => $item,
            ];
        }
        // Steps limited to the requested mode keep their authored order.
        $steps = array_values($steps);

        return [
            'content_version_id' => $mission->id,
            'id' => $mission->content_key,
            'version' => "{$mission->version_major}.{$mission->version_minor}.{$mission->version_patch}",
            'title' => $front['title'],
            'purpose' => $front['purpose'] ?? $front['title'],
            'objective' => $front['objective'],
            'estimated_minutes' => $front['time_estimate_minutes'],
            'primary_skill' => $front['primary_skill'],
            'sources' => array_map(fn ($s) => ['url' => $s['url'], 'title' => $s['title'] ?? $s['url']], $front['sources'] ?? []),
            'steps' => $steps,
        ];
    }

    public static function item(object $row): array
    {
        $b = $row->body;
        $out = [
            'content_version_id' => $row->id,
            'key' => $row->content_key,
            'type' => $b['type'],
            'scenario' => $b['scenario'] ?? null,
            'prompt' => $b['prompt'],
            'time_estimate_minutes' => $b['time_estimate_minutes'] ?? null,
            'evidence_basis' => $b['evidence_basis'],
            'hint_count' => count($b['hints'] ?? []),
        ];
        if (in_array($b['type'], ['single_choice', 'multi_select'], true)) {
            $out['options'] = array_map(fn ($o) => ['key' => $o['key'], 'text' => $o['text']], $b['options']);
        }
        if ($b['type'] === 'ordering') {
            $out['options'] = self::shuffledNotInOrder(
                array_map(fn ($o) => ['key' => $o['key'], 'text' => $o['text']], $b['options']),
                $b['answer_order'],
                $row->id,
            );
        }
        if ($b['type'] === 'self_check') {
            $out['rubric'] = array_map(fn ($c) => ['id' => $c['id'], 'weight' => $c['weight'], 'text' => $c['text']], $b['rubric']);
            // The exemplar is compared against the learner's own text before they rate it (docs/02 §5.3);
            // it is self_assessed evidence and labelled so. The UI gates it behind writing a response first.
            $out['exemplar'] = $b['exemplar'];
            $out['min_chars'] = (int) config('devstep.open_response_min_chars');
        }

        return $out;
    }

    /** A deterministic shuffle seeded by the item ID, guaranteed not to equal the answer order. */
    private static function shuffledNotInOrder(array $options, array $answerOrder, string $seed): array
    {
        $n = count($options);
        $sorted = $options;
        usort($sorted, fn ($a, $b) => crc32($seed.$a['key']) <=> crc32($seed.$b['key']));
        if (array_column($sorted, 'key') === $answerOrder) {
            $sorted[] = array_shift($sorted);
        }

        return $n > 1 ? $sorted : $options;
    }

    /** Misconception notes from the mission's `## Misconceptions` section: `- **id**: text` bullets. */
    public static function misconceptions(object $mission): array
    {
        $body = $mission->body['sections']['misconceptions']['body'] ?? '';
        $out = [];
        foreach (preg_split('/\R/', $body) as $line) {
            if (preg_match('/^- \*\*([a-z0-9.\-]+)\*\*:\s*(.+)$/', $line, $m)) {
                $out[$m[1]] = $m[2];
            }
        }

        return $out;
    }
}
