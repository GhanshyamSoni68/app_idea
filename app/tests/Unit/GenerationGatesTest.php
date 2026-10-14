<?php

namespace Tests\Unit;

use App\Modules\Generation\GenerationException;
use App\Modules\Generation\Pipeline\ContentAssembler;
use App\Modules\Generation\Pipeline\MissionDrafter;
use App\Modules\Generation\Pipeline\QualityGates;
use App\Modules\Generation\Pipeline\TopicGuard;
use App\Modules\Generation\Providers\FakeContent;
use App\Modules\Generation\Support\JsonShape;
use App\Modules\Generation\Support\JsonText;
use App\Modules\Generation\Support\Schemas;
use Tests\TestCase;

/** The deterministic guards on what a model produces. They must hold whichever model is behind the engine. */
class GenerationGatesTest extends TestCase
{
    private function mission(?callable $mutate = null): array
    {
        $topic = ['key' => 'foundations', 'title' => 'Foundations of caching', 'objective' => 'Given a case, choose a first move.', 'skill_title' => 'Caching', 'prerequisites' => [], 'misconceptions' => []];
        $draft = FakeContent::draft('Foundations of caching', 'Given a case, choose a first move.', [['name' => 'Cache everything', 'note' => 'Caching can hide the cause.']]);
        if ($mutate) {
            $draft = $mutate($draft);
        }

        return (new ContentAssembler('gtest0001'))->mission($topic, $draft, 'fake/fake-1');
    }

    private function codes(array $mission): array
    {
        return array_map(fn ($f) => $f['code'].':'.$f['severity'], (new QualityGates)->check($mission));
    }

    public function test_clean_content_passes(): void
    {
        $this->assertSame([], QualityGates::errors((new QualityGates)->check($this->mission())));
        $this->assertSame([], MissionDrafter::problems(FakeContent::draft('t', 'o', [])));
    }

    public function test_links_markup_secrets_and_personal_details_are_refused(): void
    {
        $cases = [
            'link' => ['Q02', 'See https://example.com/docs for more.'],
            'markdown link' => ['Q02', 'Read [the guide](https://example.com).'],
            'html' => ['Q01', 'Click <b>here</b> to continue the scenario of the small team.'],
            'api key' => ['Q03', 'The team pasted sk-abcdefghijklmnopqrstuv1234 into a chat.'],
            'email' => ['Q03', 'Write to ada@example.com about the failing job in the scenario.'],
            'phone' => ['Q03', 'Call 415-555-0134 when the job in the scenario fails.'],
            'pressure' => ['Q04', 'Do not fall behind: keep your streak going through this scenario.'],
            'exclaim' => ['Q04', 'The team fixed it, which is great news for the scenario!'],
            'destructive' => ['Q10', "Run this:\n\n```bash\nrm -rf /\n```\n"],
        ];
        foreach ($cases as $name => [$code, $text]) {
            $m = $this->mission(fn ($d) => $d + []);
            $m['sections']['scenario']['body'] = $text.' '.str_repeat('The team keeps trying things. ', 3);
            $this->assertContains($code.':error', $this->codes($m), $name);
        }
    }

    public function test_ordinary_technical_text_is_not_mistaken_for_those(): void
    {
        $m = $this->mission();
        $m['sections']['scenario']['body'] = "The query took 1,893 ms on 2026-10-14 and `a != b` was false. Plan: cost=0.00..48100.00 rows=21840 loops=1.\n\n```sql\nSELECT * FROM t WHERE a != b;\n```\nThe team used 4 workers and 120.5 MB.";
        $this->assertSame([], QualityGates::errors((new QualityGates)->check($m)));
    }

    public function test_absolute_claims_are_flagged_but_do_not_block(): void
    {
        $m = $this->mission();
        $m['sections']['worked-example']['body'] .= ' This always works in every case.';
        $codes = $this->codes($m);
        $this->assertContains('Q11:warning', $codes);
        $this->assertSame([], QualityGates::errors((new QualityGates)->check($m)));
    }

    public function test_size_and_hint_limits(): void
    {
        $m = $this->mission();
        $id = array_key_first($m['items']);
        $m['items'][$id]['hints'] = [];
        $this->assertContains('Q05:error', $this->codes($m));
        $m = $this->mission();
        $m['items'][$id]['prompt'] = str_repeat('x', 500);
        $this->assertContains('Q05:error', $this->codes($m));
        $m = $this->mission();
        $m['sections']['scenario']['body'] = 'Too short.';
        $this->assertContains('Q05:error', $this->codes($m));
    }

    public function test_option_rules(): void
    {
        $m = $this->mission();
        $id = $m['front']['id'].'.primary-1';
        $m['items'][$id]['options'][1]['text'] = 'All of the above are fine.';
        $this->assertContains('Q06:error', $this->codes($m));

        $m = $this->mission();
        $m['items'][$id]['options'][1]['text'] = $m['items'][$id]['options'][2]['text'];
        $this->assertContains('Q06:error', $this->codes($m));

        $m = $this->mission();
        foreach ($m['items'][$id]['options'] as $i => $o) {
            if (empty($o['correct'])) {
                $m['items'][$id]['options'][$i]['feedback'] = 'No.';
                break;
            }
        }
        $this->assertContains('Q06:error', $this->codes($m));
    }

    public function test_a_hint_may_not_give_the_answer_away(): void
    {
        $m = $this->mission();
        $id = $m['front']['id'].'.primary-1';
        $correct = collect($m['items'][$id]['options'])->firstWhere('correct', true);
        $m['items'][$id]['hints'] = ['Think about it: '.$correct['text']];
        $this->assertContains('Q09:error', $this->codes($m));
    }

    public function test_held_back_items_must_be_a_different_situation(): void
    {
        $m = $this->mission();
        $teaching = $m['sections']['scenario']['body'];
        $m['items'][$m['front']['id'].'.check-1']['scenario'] = $teaching;
        $this->assertContains('Q08:error', $this->codes($m));

        $m = $this->mission();
        $m['items'][$m['front']['id'].'.alt-a']['scenario'] = $m['items'][$m['front']['id'].'.check-1']['scenario'];
        $this->assertContains('Q08:error', $this->codes($m));

        $m = $this->mission();
        unset($m['items'][$m['front']['id'].'.alt-b']['scenario']);
        $this->assertContains('Q08:error', $this->codes($m));
    }

    public function test_the_correct_option_may_not_be_the_longest_in_most_items(): void
    {
        $m = $this->mission();
        foreach (['primary-1', 'changed-condition', 'check-1'] as $slot) {
            $id = $m['front']['id'].'.'.$slot;
            foreach ($m['items'][$id]['options'] as $i => $o) {
                $m['items'][$id]['options'][$i]['text'] = ! empty($o['correct']) ? 'A much longer and more carefully qualified answer than any other choice here.' : 'Short wrong choice '.$i;
            }
        }
        $this->assertContains('Q07:error', $this->codes($m));
    }

    public function test_duplicate_prompts_are_refused(): void
    {
        $m = $this->mission();
        $m['items'][$m['front']['id'].'.alt-a']['prompt'] = $m['items'][$m['front']['id'].'.check-1']['prompt'];
        $this->assertContains('Q13:error', $this->codes($m));
    }

    public function test_the_draft_shape_check_finds_structural_problems(): void
    {
        $d = FakeContent::draft('t', 'o', []);
        $this->assertSame([], MissionDrafter::problems($d));

        $bad = $d;
        $bad['items'][1]['slot'] = 'primary-1'; // two primary-1, no primary-2
        $this->assertNotSame([], MissionDrafter::problems($bad));

        $bad = $d;
        foreach ($bad['items'][0]['options'] as $i => $o) {
            if (! $o['correct']) {
                $bad['items'][0]['options'][$i]['correct'] = true; // two correct in a single_choice
                break;
            }
        }
        $this->assertStringContainsString('exactly one correct', implode(' ', MissionDrafter::problems($bad)));

        $bad = $d;
        $i = array_search('alt-a', array_column($bad['items'], 'slot'), true);
        $bad['items'][$i]['answer_order'] = ['a', 'b'];
        $this->assertStringContainsString('answer_order', implode(' ', MissionDrafter::problems($bad)));

        $bad = $d;
        $bad['items'][5]['type'] = 'single_choice'; // alt-b must be self_check
        $this->assertStringContainsString('alt-b', implode(' ', MissionDrafter::problems($bad)));

        $this->assertNotSame([], MissionDrafter::problems(null));
        $this->assertNotSame([], MissionDrafter::problems(['items' => []]));
    }

    public function test_topic_guard(): void
    {
        $this->assertSame('Docker networking basics', TopicGuard::normalise("  Docker\n networking   basics "));
        foreach (['ab', str_repeat('a', 130), 'see https://x.io', 'a <script> topic', 'mail me@example.com', str_repeat('Ab3', 12), '12345 67890 !!!!', 'how to write ransomware', 'build a keylogger to deploy', 'bypass the paywall licence'] as $bad) {
            try {
                TopicGuard::normalise($bad);
                $this->fail("Should have refused: {$bad}");
            } catch (GenerationException $e) {
                $this->assertSame('topic_rejected', $e->code_, $bad);
            }
        }
    }

    public function test_json_shape_and_extraction(): void
    {
        $schema = Schemas::critique();
        $this->assertSame([], JsonShape::check(['verdict' => 'pass', 'issues' => [], 'unsupported_claims' => []], $schema));
        $problems = implode(' ', JsonShape::check(['verdict' => 'maybe', 'issues' => [['slot' => 'a']], 'extra' => 1], $schema));
        $this->assertStringContainsString('must be one of', $problems);
        $this->assertStringContainsString('severity: missing', $problems);
        $this->assertStringContainsString('extra: not allowed', $problems);
        $this->assertStringContainsString('expected string', implode(' ', JsonShape::check(['verdict' => 1, 'issues' => [], 'unsupported_claims' => []], $schema)));

        $this->assertSame(['a' => 1], JsonText::object('{"a":1}'));
        $this->assertSame(['a' => 1], JsonText::object("Sure!\n```json\n{\"a\":1}\n```"));
        $this->assertSame(['a' => 1], JsonText::object('Here you go: {"a":1} hope it helps'));
        $this->assertNull(JsonText::object('no json here'));
        $this->assertNull(JsonText::object('[1,2]'));
    }
}
