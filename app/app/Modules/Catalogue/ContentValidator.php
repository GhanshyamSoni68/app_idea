<?php

namespace App\Modules\Catalogue;

/**
 * Publish-blocking validation (docs/06 §9). Findings are errors unless the run is in development mode,
 * where review-state rules and missing missions become warnings. Production never downgrades anything.
 */
final class ContentValidator
{
    public const ITEM_TYPES = ['single_choice', 'multi_select', 'ordering', 'self_check'];

    private const ID_PATTERN = '/^[a-z][a-z0-9-]*(\.[a-z][a-z0-9-]*)+$/';

    /** @var list<array{severity:string,code:string,path:string,message:string}> */
    private array $findings = [];

    /**
     * @param  array<string, mixed>  $content  Output of ContentLoader::load()
     * @param  array{allow_unreviewed?:bool, allow_partial?:bool, allow_unsourced?:bool}  $options
     * @return list<array{severity:string,code:string,path:string,message:string}>
     */
    public function validate(array $content, array $options = []): array
    {
        $this->findings = [];
        $lenientReview = (bool) ($options['allow_unreviewed'] ?? false);
        $partial = (bool) ($options['allow_partial'] ?? false);
        $unsourced = (bool) ($options['allow_unsourced'] ?? false);

        $this->skills($content);
        foreach ($content['missions'] as $dirId => $mission) {
            $this->mission((string) $dirId, $mission, $content, $lenientReview, $unsourced);
        }
        foreach ($content['roadmaps'] as $slug => $roadmap) {
            $this->roadmap((string) $slug, $roadmap, $content, $partial);
        }

        return $this->findings;
    }

    /** @param list<array{severity:string,code:string,path:string,message:string}> $findings */
    public static function hasErrors(array $findings): bool
    {
        foreach ($findings as $f) {
            if ($f['severity'] === 'error') {
                return true;
            }
        }

        return false;
    }

    private function add(string $severity, string $code, string $path, string $message): void
    {
        $this->findings[] = compact('severity', 'code', 'path', 'message');
    }

    private function error(string $code, string $path, string $message): void
    {
        $this->add('error', $code, $path, $message);
    }

    private function skills(array $content): void
    {
        $ids = array_keys($content['skills']);
        foreach ($ids as $id) {
            if (! preg_match(self::ID_PATTERN, (string) $id)) {
                $this->error('V03', "skills/{$id}", 'Skill ID must be dotted lowercase, for example perf.query-plans.');
            }
        }
        $graph = [];
        foreach ($content['skill_prerequisites'] as $edge) {
            [$from, $to] = $edge + [null, null];
            if (! isset($content['skills'][$from], $content['skills'][$to])) {
                $this->error('V12', 'skills/prerequisites', "Edge {$from} -> {$to} names an unknown skill.");

                continue;
            }
            if ($from === $to) {
                $this->error('V12', 'skills/prerequisites', "Skill {$from} lists itself as a prerequisite.");
            }
            $graph[$to][] = $from;
        }
        if ($cycle = $this->findCycle($graph)) {
            $this->error('V12', 'skills/prerequisites', 'Skill prerequisite cycle: '.implode(' -> ', $cycle));
        }
    }

    private function mission(string $dirId, array $mission, array $content, bool $lenientReview, bool $unsourced = false): void
    {
        $front = $mission['front'];
        $path = "missions/{$dirId}";
        $id = $front['id'] ?? null;

        foreach (['id', 'version', 'status', 'title', 'objective', 'objective_key', 'objective_major', 'primary_skill',
            'scenario_key', 'time_estimate_minutes', 'steps', 'sources', 'stack_scope', 'author'] as $field) {
            if (! array_key_exists($field, $front)) {
                $this->error('V01', $path, "Front matter is missing `{$field}`.");
            }
        }
        if ($id !== $dirId) {
            $this->error('V02', $path, "Mission id `{$id}` must equal its directory name `{$dirId}`.");
        }
        if (! preg_match(self::ID_PATTERN, $dirId)) {
            $this->error('V03', $path, 'Mission ID must be dotted lowercase.');
        }
        if (! preg_match('/^\d+\.\d+\.\d+$/', (string) ($front['version'] ?? ''))) {
            $this->error('V04', $path, 'Version must be X.Y.Z.');
        }
        if (! isset($content['skills'][$front['primary_skill'] ?? ''])) {
            $this->error('V05', $path, 'Primary skill `'.($front['primary_skill'] ?? '').'` is not in skills.yaml.');
        }
        foreach ($front['secondary_skills'] ?? [] as $s) {
            if (! isset($content['skills'][$s])) {
                $this->error('V05', $path, "Secondary skill `{$s}` is not in skills.yaml.");
            }
        }
        if (isset($front['time_limit']) || isset($front['countdown'])) {
            $this->error('V15', $path, 'Time limits and countdowns are not allowed (PRD §5).');
        }

        // Steps reference sections and items.
        $stepKeys = [];
        foreach ($front['steps'] ?? [] as $i => $step) {
            $key = $step['key'] ?? null;
            $stepKeys[] = $key;
            $kind = $step['kind'] ?? null;
            if (! in_array($kind, ['scenario', 'explanation', 'response'], true)) {
                $this->error('V06', $path, "Step {$i} has unknown kind `{$kind}`.");
            }
            if (in_array($kind, ['scenario', 'explanation'], true) && ! isset($mission['sections'][$key])) {
                $this->error('V06', $path, "Step `{$key}` needs a section headed `## ".ucfirst(str_replace('-', ' ', (string) $key)).'`.');
            }
            if ($kind === 'response') {
                $item = $step['item'] ?? null;
                if (! isset($mission['items'][$item])) {
                    $this->error('V06', $path, "Step `{$key}` references item `{$item}` which does not exist.");
                }
            }
        }
        if (count($stepKeys) !== count(array_unique($stepKeys))) {
            $this->error('V06', $path, 'Step keys must be unique.');
        }

        // Small variant (PRD §9): a curated subset, at most 4 minutes, one task.
        $small = $front['small_variant'] ?? null;
        if (! is_array($small) || empty($small['steps'])) {
            $this->error('V14', $path, 'A curated small_variant is required (PRD §9).');
        } else {
            $minutes = 0.0;
            $responses = 0;
            foreach ($small['steps'] as $sk) {
                if (! in_array($sk, $stepKeys, true)) {
                    $this->error('V14', $path, "small_variant step `{$sk}` is not a step of this mission.");

                    continue;
                }
                $step = collect($front['steps'])->firstWhere('key', $sk);
                if (($step['kind'] ?? '') === 'response') {
                    $responses++;
                    $minutes += (float) ($mission['items'][$step['item']]['time_estimate_minutes'] ?? 1);
                }
            }
            if ($responses !== 1) {
                $this->error('V14', $path, 'A small variant holds exactly one recall or decision task.');
            }
            if (($front['time_estimate_minutes']['small'] ?? 99) > (int) 4) {
                $this->error('V14', $path, 'Small variant estimate must be 4 minutes or less.');
            }
        }

        // Items.
        $roles = ['primary' => 0, 'topic_check' => 0, 'alternate' => 0];
        $altA = $altB = false;
        $scenario = $front['scenario_key'] ?? null;
        foreach ($mission['items'] as $itemId => $item) {
            $this->item($itemId, $item, $dirId, $scenario, $mission, $roles, $altA, $altB);
        }
        if ($roles['topic_check'] < 1) {
            $this->error('V07', $path, 'At least one topic_check item is required.');
        }
        if ($roles['alternate'] < 2 || ! $altA || ! $altB) {
            $this->error('V07', $path, 'At least two alternates are required: Alt A (check_eligible) and Alt B (retention_reserved).');
        }
        foreach (($front['held_back']['topic_check'] ?? []) as $ref) {
            if (! isset($mission['items'][$ref])) {
                $this->error('V06', $path, "held_back topic_check `{$ref}` does not exist.");
            }
        }
        foreach (($front['held_back']['alternates'] ?? []) as $ref) {
            if (! isset($mission['items'][$ref])) {
                $this->error('V06', $path, "held_back alternate `{$ref}` does not exist.");
            }
        }

        // Sources (PRD §8).
        if (empty($front['sources'])) {
            // Generated content (docs/16) has no sources: it cannot cite what no person has opened. It is labelled instead.
            $this->add($unsourced ? 'warning' : 'error', 'V10', $path, 'At least one source with a URL and an accessed date is required.');
        }
        foreach ($front['sources'] ?? [] as $s) {
            if (empty($s['url']) || ! preg_match('#^https://#', (string) $s['url']) || empty($s['accessed'])) {
                $this->error('V10', $path, 'Each source needs an https URL and an accessed date.');
            }
        }

        // Markdown must not carry raw HTML (safe rendering, PRD §12).
        foreach ($mission['sections'] as $key => $section) {
            $body = preg_replace('/```.*?```/s', '', $section['body']);
            if (preg_match('/<\/?[a-zA-Z][^>]*>/', (string) $body)) {
                $this->error('V22', "{$path}#{$key}", 'Raw HTML in Markdown is not allowed.');
            }
        }

        // Review state (PRD §8): reviewer other than author, exercise tried, accessibility reviewed.
        $sev = $lenientReview ? 'warning' : 'error';
        $review = $front['review'] ?? [];
        $reviewer = $review['reviewer'] ?? null;
        if (($front['status'] ?? '') === 'published') {
            if (! $reviewer || $reviewer === 'pending' || $reviewer === ($front['author'] ?? null)) {
                $this->add($sev, 'V11', $path, 'A reviewer other than the author must be recorded before publishing.');
            }
            if (empty($review['tried_exercise'])) {
                $this->add($sev, 'V11', $path, 'The reviewer must have tried the exercise.');
            }
            if (($front['accessibility']['reviewed_by'] ?? 'pending') === 'pending') {
                $this->add($sev, 'V16', $path, 'Accessibility review is not recorded.');
            }
        }
    }

    private function item(string $itemId, array $item, string $missionId, ?string $teachingScenario, array $mission, array &$roles, bool &$altA, bool &$altB): void
    {
        $path = "missions/{$missionId}/items/{$itemId}";
        if (! str_starts_with($itemId, $missionId.'.')) {
            $this->error('V03', $path, "Item ID must start with `{$missionId}.`.");
        }
        foreach (['role', 'type', 'prompt', 'hints', 'reveal', 'scenario_key', 'evidence_basis'] as $f) {
            if (! isset($item[$f]) || $item[$f] === '' || $item[$f] === []) {
                $this->error('V08', $path, "Item is missing `{$f}`.");
            }
        }
        if (isset($item['time_limit']) || isset($item['countdown'])) {
            $this->error('V15', $path, 'Items have no time limit (PRD §5).');
        }
        $type = $item['type'] ?? null;
        if (! in_array($type, self::ITEM_TYPES, true)) {
            $this->error('V08', $path, "Item type `{$type}` is not supported yet.");
        }
        $role = $item['role'] ?? null;
        if (isset($roles[$role])) {
            $roles[$role]++;
        }
        if ($role === 'topic_check' && empty($item['check_eligible'])) {
            $this->error('V07', $path, 'A topic_check item must be check_eligible.');
        }
        if ($role === 'alternate') {
            $altA = $altA || ! empty($item['check_eligible']);
            $altB = $altB || ! empty($item['retention_reserved']);
        }
        if (! empty($item['check_eligible']) && ! empty($item['retention_reserved'])) {
            $this->error('V07', $path, 'An item cannot be both check_eligible and retention_reserved.');
        }
        // Held-back items must use a different scenario (docs/05 "different scenario").
        if (in_array($role, ['topic_check', 'alternate'], true) && ($item['scenario_key'] ?? null) === $teachingScenario) {
            $this->error('V18', $path, 'A held-back item must use a different scenario_key from the teaching scenario.');
        }
        if (isset($item['changed_conditions_of']) && ! isset($mission['items'][$item['changed_conditions_of']])) {
            $this->error('V20', $path, "changed_conditions_of `{$item['changed_conditions_of']}` does not exist.");
        }
        if ((float) ($item['time_estimate_minutes'] ?? 0) > 2 && in_array($role, ['topic_check', 'alternate'], true)) {
            $this->error('V14', $path, 'A retrieval item must be 2 minutes or less.');
        }

        if ($type === 'single_choice' || $type === 'multi_select') {
            $options = $item['options'] ?? [];
            $keys = array_column($options, 'key');
            if (count($options) < 2 || count($keys) !== count(array_unique($keys))) {
                $this->error('V19', $path, 'Options need unique keys and at least two choices.');
            }
            $correct = count(array_filter($options, fn ($o) => ! empty($o['correct'])));
            if ($type === 'single_choice' && $correct !== 1) {
                $this->error('V19', $path, 'A single_choice item needs exactly one correct option.');
            }
            if ($type === 'multi_select' && $correct < 1) {
                $this->error('V19', $path, 'A multi_select item needs at least one correct option.');
            }
            foreach ($options as $o) {
                if (empty($o['feedback'])) {
                    $this->error('V09', $path, "Option `{$o['key']}` needs feedback.");
                }
            }
            if (($item['evidence_basis'] ?? '') !== 'auto_scored') {
                $this->error('V08', $path, 'Structured items are auto_scored.');
            }
        }
        if ($type === 'ordering') {
            $keys = array_column($item['options'] ?? [], 'key');
            $order = $item['answer_order'] ?? [];
            if (count($keys) < 3 || array_diff($keys, $order) || array_diff($order, $keys) || count($order) !== count($keys)) {
                $this->error('V19', $path, 'answer_order must list every option key exactly once.');
            }
            if (empty($item['feedback_correct']) || empty($item['feedback_incorrect'])) {
                $this->error('V09', $path, 'Ordering items need feedback_correct and feedback_incorrect.');
            }
        }
        if ($type === 'self_check') {
            if (($item['evidence_basis'] ?? '') !== 'self_assessed') {
                $this->error('V08', $path, 'self_check items are self_assessed.');
            }
            $rubric = $item['rubric'] ?? [];
            $essential = array_filter($rubric, fn ($c) => ($c['weight'] ?? '') === 'essential');
            if (count($rubric) < 2 || count($essential) < 1 || empty($item['exemplar'])) {
                $this->error('V08', $path, 'self_check items need a rubric with an essential criterion and an exemplar.');
            }
        }
    }

    private function roadmap(string $slug, array $roadmap, array $content, bool $partial): void
    {
        foreach ($roadmap['versions'] as $n => $version) {
            $path = "roadmaps/{$slug}/versions/v{$n}";
            if (($version['roadmap'] ?? null) !== $slug) {
                $this->error('V02', $path, 'roadmap field must equal the roadmap directory name.');
            }
            $topics = [];
            $required = 0;
            foreach ($version['modules'] ?? [] as $module) {
                foreach ($module['topics'] ?? [] as $t) {
                    $id = $t['id'] ?? '?';
                    if (isset($topics[$id])) {
                        $this->error('V13', $path, "Duplicate topic `{$id}`.");
                    }
                    $topics[$id] = $t;
                    if (($t['kind'] ?? '') === 'required') {
                        $required++;
                    }
                    if (! in_array($t['kind'] ?? '', ['required', 'optional'], true)) {
                        $this->error('V13', $path, "Topic `{$id}` needs kind required or optional.");
                    }
                    $m = $t['mission'] ?? null;
                    if ($m !== null && ! isset($content['missions'][$m])) {
                        $this->add($partial ? 'warning' : 'error', 'V13', $path, "Topic `{$id}` references mission `{$m}` which has no content yet.");
                    }
                }
            }
            if ($required < 1) {
                $this->error('V13', $path, 'A roadmap version needs at least one required topic.');
            }
            $graph = [];
            foreach ($topics as $id => $t) {
                foreach ($t['prerequisites'] ?? [] as $p) {
                    $dep = $p['topic'] ?? null;
                    if (! isset($topics[$dep])) {
                        $this->error('V13', $path, "Topic `{$id}` depends on unknown topic `{$dep}`.");

                        continue;
                    }
                    if (($topics[$dep]['kind'] ?? '') === 'optional' && ($t['kind'] ?? '') === 'required') {
                        $this->error('V13', $path, "Required topic `{$id}` cannot depend on optional `{$dep}` (PR-3).");
                    }
                    $graph[$id][] = $dep;
                }
            }
            if ($cycle = $this->findCycle($graph)) {
                $this->error('V13', $path, 'Prerequisite cycle: '.implode(' -> ', $cycle).' (PRD §8A).');
            }
        }
    }

    /** @param array<string, list<string>> $graph node -> dependencies @return list<string>|null */
    private function findCycle(array $graph): ?array
    {
        $state = [];
        $stack = [];
        $visit = function (string $n) use (&$visit, &$state, &$stack, $graph): ?array {
            $state[$n] = 1;
            $stack[] = $n;
            foreach ($graph[$n] ?? [] as $m) {
                if (($state[$m] ?? 0) === 1) {
                    return array_merge(array_slice($stack, (int) array_search($m, $stack, true)), [$m]);
                }
                if (($state[$m] ?? 0) === 0 && ($c = $visit($m))) {
                    return $c;
                }
            }
            $state[$n] = 2;
            array_pop($stack);

            return null;
        };
        foreach (array_keys($graph) as $n) {
            if (($state[$n] ?? 0) === 0 && ($c = $visit((string) $n))) {
                return $c;
            }
        }

        return null;
    }
}
