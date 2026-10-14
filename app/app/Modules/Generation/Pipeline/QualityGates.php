<?php

namespace App\Modules\Generation\Pipeline;

/**
 * Deterministic checks on generated content (docs/16 §5). They need no model, so they hold whichever model wrote the
 * content. A finding is an `error` (the content is not kept as it is) or a `warning` (recorded, not blocking).
 *
 * Input is one mission in ContentLoader's shape: ['front' => [...], 'sections' => [...], 'items' => [...]].
 */
final class QualityGates
{
    /** Pressure, guilt and judgement words the product never uses (docs/15 §6). Mirrors resources/js/lib/stepSays.test.ts. */
    private const PRESSURE = '/\b(missed|behind|overdue|streak|you lost|lost your|lose your|hurry|falling behind|disappoint\w*|miss you|give up|lazy|deadline|last chance|don\'t lose|leaderboard|everyone else|other learners)\b/i';

    private const SECRETS = [
        '/\b(sk|pk|rk)-[A-Za-z0-9_\-]{16,}\b/',
        '/\bAKIA[0-9A-Z]{16}\b/',
        '/\bgh[pousr]_[A-Za-z0-9]{30,}\b/',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        '/\beyJ[A-Za-z0-9_\-]{20,}\.[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\b/',
        '/\b[A-Fa-f0-9]{40,}\b/',
    ];

    private const PII = [
        'an email address' => '/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/',
        'a phone number' => '/(?<![\d.])(?:\+\d{1,3}[\s.\-]?\(?\d{2,4}\)?[\s.\-]?\d{3,4}[\s.\-]?\d{3,4}|\(\d{3}\)\s?\d{3}[\s\-]\d{4}|\d{3}[\s.\-]\d{3}[\s.\-]\d{4})(?![\d.])/',
    ];

    private const DANGEROUS = [
        '/\brm\s+-rf\s+(\/|~|\*)/i', '/\bcurl\b[^\n|]*\|\s*(sudo\s+)?(ba)?sh\b/i', '/:\(\)\s*\{\s*:\|:&\s*\};:/', '/\bmkfs\./i',
        '/\bchmod\s+-R\s+777\s+\//i', '/\bdd\s+if=\S+\s+of=\/dev\//i',
    ];

    private const LIMITS = [
        'scenario' => 1400, 'scenario_short' => 600, 'worked_example' => 2200, 'prompt' => 450, 'option' => 260, 'feedback' => 520,
        'hint' => 320, 'reveal' => 520, 'rubric' => 260, 'exemplar' => 700, 'step' => 200,
    ];

    /** @var list<array{severity:string, code:string, path:string, message:string}> */
    private array $findings = [];

    /**
     * @param  array<string, mixed>  $mission
     * @return list<array{severity:string, code:string, path:string, message:string}>
     */
    public function check(array $mission): array
    {
        $this->findings = [];
        $front = $mission['front'] ?? [];
        $items = $mission['items'] ?? [];

        foreach ($this->texts($mission) as $path => $text) {
            $this->text($path, $text);
        }
        $this->sizes($mission);
        foreach ($items as $id => $item) {
            $this->item((string) $id, $item);
        }
        $this->distinctScenarios($mission);
        $this->keyBias($items);
        $this->duplicates($items);

        return $this->findings;
    }

    /**
     * The text rules on their own, for strings that are not part of a mission yet (a path's title and objectives).
     *
     * @param  array<string, string>  $texts  path => text
     * @return list<array{severity:string, code:string, path:string, message:string}>
     */
    public function checkTexts(array $texts): array
    {
        $this->findings = [];
        foreach ($texts as $path => $text) {
            $this->text($path, $text);
        }

        return $this->findings;
    }

    /** @param  list<array{severity:string,code:string,path:string,message:string}>  $findings */
    public static function errors(array $findings): array
    {
        return array_values(array_filter($findings, fn ($f) => $f['severity'] === 'error'));
    }

    private function add(string $severity, string $code, string $path, string $message): void
    {
        $this->findings[] = compact('severity', 'code', 'path', 'message');
    }

    /** Every string a learner can read, with a path for the log. @return array<string, string> */
    private function texts(array $mission): array
    {
        $out = [];
        $front = $mission['front'] ?? [];
        foreach (['title', 'purpose', 'objective'] as $k) {
            if (isset($front[$k])) {
                $out["front.{$k}"] = (string) $front[$k];
            }
        }
        foreach ($mission['sections'] ?? [] as $key => $section) {
            $out["section.{$key}"] = (string) ($section['body'] ?? '');
        }
        foreach ($mission['items'] ?? [] as $id => $item) {
            foreach (['scenario', 'prompt', 'reveal', 'exemplar', 'feedback_correct', 'feedback_incorrect'] as $k) {
                if (! empty($item[$k])) {
                    $out["{$id}.{$k}"] = (string) $item[$k];
                }
            }
            foreach ($item['hints'] ?? [] as $i => $h) {
                $out["{$id}.hint[{$i}]"] = (string) $h;
            }
            foreach ($item['options'] ?? [] as $o) {
                $out["{$id}.option.{$o['key']}"] = (string) ($o['text'] ?? '');
                if (! empty($o['feedback'])) {
                    $out["{$id}.option.{$o['key']}.feedback"] = (string) $o['feedback'];
                }
            }
            foreach ($item['rubric'] ?? [] as $c) {
                $out["{$id}.rubric.{$c['id']}"] = (string) ($c['text'] ?? '');
            }
        }

        return $out;
    }

    private function text(string $path, string $text): void
    {
        $prose = preg_replace('/```.*?```/s', '', $text) ?? $text;
        if (preg_match('/<\/?[a-zA-Z][^>]*>/', $prose)) {
            $this->add('error', 'Q01', $path, 'Raw HTML or markup is not allowed.');
        }
        if (preg_match('#(https?://|www\.)\S+#i', $text) || preg_match('/\]\(\s*(?!#)[^)]+\)/', $text)) {
            $this->add('error', 'Q02', $path, 'Generated content may not contain links: it cannot cite a source a person has opened.');
        }
        foreach (self::SECRETS as $re) {
            if (preg_match($re, $text)) {
                $this->add('error', 'Q03', $path, 'Looks like a secret or key.');

                break;
            }
        }
        foreach (self::PII as $what => $re) {
            if (preg_match($re, $prose)) {
                $this->add('error', 'Q03', $path, "Contains {$what}. Scenarios use fictional, anonymous detail only.");
            }
        }
        $plain = preg_replace('/`[^`]*`/', '', $prose) ?? $prose; // inline code such as `!=` is not tone
        if (preg_match(self::PRESSURE, $plain, $m)) {
            $this->add('error', 'Q04', $path, "Pressure or guilt wording (\"{$m[0]}\") is not allowed (PRD §6).");
        }
        if (str_contains($plain, '!') || preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $plain)) {
            $this->add('error', 'Q04', $path, 'No exclamation marks or emoji: the tone is calm.');
        }
        foreach (self::DANGEROUS as $re) {
            if (preg_match($re, $text)) {
                $this->add('error', 'Q10', $path, 'Contains a destructive command that a learner might copy.');

                break;
            }
        }
        if (preg_match('/\b(always|never|guaranteed|impossible|the only way)\b/i', $plain)) {
            $this->add('warning', 'Q11', $path, 'An absolute claim; check it holds in every case.');
        }
        if (preg_match('/```[^\n`]*\n(?:.*\n){41,}```/', $text)) {
            $this->add('error', 'Q05', $path, 'A code block is longer than 40 lines.');
        }
    }

    private function sizes(array $mission): void
    {
        $sec = fn (string $k) => (string) ($mission['sections'][$k]['body'] ?? '');
        $this->limit('section.scenario', $sec('scenario'), self::LIMITS['scenario']);
        $this->limit('section.scenario-short', $sec('scenario-short'), self::LIMITS['scenario_short']);
        $this->limit('section.worked-example', $sec('worked-example'), self::LIMITS['worked_example']);
        foreach (['scenario', 'worked-example'] as $k) {
            if (mb_strlen($sec($k)) < 60) {
                $this->add('error', 'Q05', "section.{$k}", 'Too short to teach anything.');
            }
        }
        foreach ($mission['items'] ?? [] as $id => $item) {
            $this->limit("{$id}.prompt", (string) ($item['prompt'] ?? ''), self::LIMITS['prompt']);
            $this->limit("{$id}.scenario", (string) ($item['scenario'] ?? ''), self::LIMITS['scenario']);
            $this->limit("{$id}.reveal", (string) ($item['reveal'] ?? ''), self::LIMITS['reveal']);
            $this->limit("{$id}.exemplar", (string) ($item['exemplar'] ?? ''), self::LIMITS['exemplar']);
            foreach ($item['hints'] ?? [] as $i => $h) {
                $this->limit("{$id}.hint[{$i}]", (string) $h, self::LIMITS['hint']);
            }
            $n = count($item['hints'] ?? []);
            if ($n < 1 || $n > 3) {
                $this->add('error', 'Q05', "{$id}.hints", "Needs 1 to 3 graduated hints, has {$n}.");
            }
            foreach ($item['options'] ?? [] as $o) {
                $this->limit("{$id}.option.{$o['key']}", (string) ($o['text'] ?? ''), self::LIMITS['option']);
                $this->limit("{$id}.option.{$o['key']}.feedback", (string) ($o['feedback'] ?? ''), self::LIMITS['feedback']);
            }
            $this->limit("{$id}.feedback_correct", (string) ($item['feedback_correct'] ?? ''), self::LIMITS['feedback']);
            $this->limit("{$id}.feedback_incorrect", (string) ($item['feedback_incorrect'] ?? ''), self::LIMITS['feedback']);
        }
    }

    private function limit(string $path, string $text, int $max): void
    {
        if (mb_strlen($text) > $max) {
            $this->add('error', 'Q05', $path, 'Too long ('.mb_strlen($text)." characters, the limit is {$max}).");
        }
    }

    private function item(string $id, array $item): void
    {
        $type = $item['type'] ?? '';
        if ($type === 'single_choice' || $type === 'multi_select') {
            $options = $item['options'] ?? [];
            if (count($options) < 3 || count($options) > 5) {
                $this->add('error', 'Q06', $id, 'Needs 3 to 5 options.');
            }
            $texts = array_map(fn ($o) => mb_strtolower(trim((string) ($o['text'] ?? ''))), $options);
            if (count($texts) !== count(array_unique($texts))) {
                $this->add('error', 'Q06', $id, 'Two options say the same thing.');
            }
            foreach ($texts as $t) {
                if (preg_match('/\b(all|none) of the above\b/', $t)) {
                    $this->add('error', 'Q06', $id, '"All of the above" and "none of the above" are not allowed.');
                }
            }
            foreach ($options as $o) {
                if (empty($o['correct']) && mb_strlen((string) ($o['feedback'] ?? '')) < 20) {
                    $this->add('error', 'Q06', "{$id}.option.{$o['key']}", 'A wrong option needs feedback that says why it fails.');
                }
            }
            // A hint must not hand over the answer.
            foreach ($options as $o) {
                if (! empty($o['correct']) && mb_strlen((string) $o['text']) >= 18) {
                    foreach ($item['hints'] ?? [] as $i => $h) {
                        if (str_contains(mb_strtolower((string) $h), mb_strtolower(trim((string) $o['text'], ". \t")))) {
                            $this->add('error', 'Q09', "{$id}.hint[{$i}]", 'A hint repeats the correct option word for word.');
                        }
                    }
                }
            }
        }
        if ($type === 'ordering') {
            $n = count($item['options'] ?? []);
            if ($n < 3 || $n > 6) {
                $this->add('error', 'Q06', $id, 'An ordering item needs 3 to 6 steps.');
            }
        }
    }

    /** Held-back items must be a genuinely different situation, or "transfer" is only memory (docs/05 "different scenario"). */
    private function distinctScenarios(array $mission): void
    {
        $teaching = $this->tokens(($mission['sections']['scenario']['body'] ?? '').' '.($mission['sections']['worked-example']['body'] ?? ''));
        $seen = [];
        foreach ($mission['items'] ?? [] as $id => $item) {
            if (! in_array($item['role'] ?? '', ['topic_check', 'alternate'], true)) {
                continue;
            }
            $t = $this->tokens((string) ($item['scenario'] ?? ''));
            if ($t === []) {
                $this->add('error', 'Q08', $id, 'A held-back item needs its own scenario.');

                continue;
            }
            if ($this->jaccard($t, $teaching) > 0.45) {
                $this->add('error', 'Q08', $id, 'Its scenario is too close to the teaching scenario to test transfer.');
            }
            foreach ($seen as $other => $ot) {
                if ($this->jaccard($t, $ot) > 0.5) {
                    $this->add('error', 'Q08', $id, "Its scenario is too close to {$other}.");
                }
            }
            $seen[$id] = $t;
        }
    }

    /** The right answer should not be recognisable by length alone. */
    private function keyBias(array $items): void
    {
        $longest = 0;
        $total = 0;
        foreach ($items as $item) {
            if (($item['type'] ?? '') !== 'single_choice') {
                continue;
            }
            $lens = [];
            foreach ($item['options'] ?? [] as $o) {
                $lens[$o['key']] = mb_strlen((string) $o['text']);
            }
            $correct = collect($item['options'] ?? [])->firstWhere('correct', true);
            if (! $correct || count($lens) < 3) {
                continue;
            }
            $total++;
            if ($lens[$correct['key']] >= max($lens) && count(array_filter($lens, fn ($l) => $l === max($lens))) === 1) {
                $longest++;
            }
        }
        if ($total >= 3 && $longest / $total > 0.6) {
            $this->add('error', 'Q07', 'items', 'The correct option is the longest in most single-choice items, so the answer can be guessed by length.');
        }
    }

    private function duplicates(array $items): void
    {
        $prompts = [];
        foreach ($items as $id => $item) {
            $p = mb_strtolower(trim((string) ($item['prompt'] ?? '')));
            if ($p !== '' && isset($prompts[$p])) {
                $this->add('error', 'Q13', $id, "Same prompt as {$prompts[$p]}.");
            }
            $prompts[$p] = $id;
        }
    }

    /** @return array<string, true> */
    private function tokens(string $text): array
    {
        $stop = array_flip(['the', 'and', 'for', 'with', 'that', 'this', 'are', 'was', 'were', 'has', 'have', 'but', 'not', 'you', 'your', 'from', 'into', 'than', 'then', 'they', 'them', 'their', 'there', 'what', 'when', 'which', 'would', 'could', 'should', 'about', 'after', 'before', 'all', 'one', 'two', 'its', 'can', 'out', 'who', 'how', 'why']);
        $out = [];
        foreach (preg_split('/[^a-z0-9]+/', mb_strtolower($text)) ?: [] as $w) {
            if (mb_strlen($w) >= 3 && ! isset($stop[$w])) {
                $out[$w] = true;
            }
        }

        return $out;
    }

    /** @param  array<string, true>  $a @param  array<string, true>  $b */
    private function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $inter = count(array_intersect_key($a, $b));

        return $inter / (count($a) + count($b) - $inter);
    }
}
