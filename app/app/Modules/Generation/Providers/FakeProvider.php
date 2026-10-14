<?php

namespace App\Modules\Generation\Providers;

use App\Modules\Generation\Contracts\LlmProvider;
use App\Modules\Generation\Llm\LlmException;
use App\Modules\Generation\Llm\LlmRequest;
use App\Modules\Generation\Llm\LlmResponse;
use Closure;

/**
 * Offline and deterministic. It makes the whole pipeline testable without a model, and lets the product be demoed with
 * no keys. Tests can script its replies with FakeProvider::script(), including failures. It refuses to run in production.
 */
final class FakeProvider implements LlmProvider
{
    /** @var array<string, list<Closure|array|LlmException>> task => queued replies */
    private static array $script = [];

    /** @var list<array{task:string, provider:string}> */
    public static array $calls = [];

    /** @param  array<string, mixed>  $config */
    public function __construct(private string $id, private array $config = []) {}

    /** Queue a reply for a task: an array (the JSON), a closure(LlmRequest): array, or an LlmException to throw. */
    public static function script(string $task, array|Closure|LlmException $reply): void
    {
        self::$script[$task][] = $reply;
    }

    public static function reset(): void
    {
        self::$script = [];
        self::$calls = [];
    }

    public function id(): string
    {
        return $this->id;
    }

    public function model(): string
    {
        return (string) ($this->config['model'] ?? 'fake-1');
    }

    public function price(): array
    {
        return ['in' => 0.0, 'out' => 0.0];
    }

    public function problem(): ?string
    {
        return app()->isProduction() ? 'The fake provider is disabled in production.' : null;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        if ($reason = $this->problem()) {
            throw new LlmException(LlmException::UNAVAILABLE, $reason);
        }
        self::$calls[] = ['task' => $request->task, 'provider' => $this->id];

        $queue = &self::$script[$request->task];
        $reply = $queue ? array_shift($queue) : null;
        unset($queue);
        if ($reply instanceof LlmException) {
            throw $reply;
        }
        if ($reply instanceof Closure) {
            $reply = $reply($request);
        }
        $json = $reply ?? $this->default($request);
        $text = json_encode($json);

        return new LlmResponse(
            text: $text, json: $json, provider: $this->id, model: $this->model(),
            inputTokens: (int) (strlen($request->system.json_encode($request->messages)) / 4), outputTokens: (int) (strlen($text) / 4),
            latencyMs: 1, finishReason: 'end_turn',
        );
    }

    /** @return array<string, mixed> */
    private function default(LlmRequest $r): array
    {
        $topic = (string) ($r->meta['topic'] ?? 'the topic');

        return match ($r->task) {
            'plan' => FakeContent::plan($topic, (int) ($r->meta['topic_count'] ?? 4)),
            'draft' => FakeContent::draft($topic, (string) ($r->meta['objective'] ?? "Given a short scenario, decide a sensible first move for {$topic}."), (array) ($r->meta['misconceptions'] ?? [])),
            'solve' => $this->solve($r, $topic),
            'critique' => ['verdict' => 'pass', 'issues' => [], 'unsupported_claims' => []],
            default => [],
        };
    }

    /** Recomputes the fake author's answers from the item shapes alone, as an honest blind solver would reach them. */
    private function solve(LlmRequest $r, string $topic): array
    {
        $answers = [];
        foreach ((array) ($r->meta['items'] ?? []) as $item) {
            $keys = (array) $item['option_keys'];
            $good = FakeContent::correct($topic, $item['slot'], $item['type'], $keys);
            $answers[] = [
                'slot' => $item['slot'],
                'chosen' => $item['type'] === 'ordering' ? [] : $good,
                'order' => $item['type'] === 'ordering' ? $good : [],
                'confidence' => 0.9, 'ambiguous' => false, 'note' => '',
            ];
        }

        return ['answers' => $answers];
    }
}
