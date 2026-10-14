<?php

namespace App\Modules\Generation;

use App\Modules\Generation\Contracts\LlmProvider;
use App\Modules\Generation\Llm\LlmException;
use App\Modules\Generation\Llm\LlmRequest;
use App\Modules\Generation\Llm\LlmResponse;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Chooses a provider for each task and keeps generation working when one misbehaves. This is the only place that knows
 * about routes, retries, the circuit breaker and cost, so every provider behaves the same from the pipeline's view.
 *
 * Per call: walk the route for the task (healthy providers first); retry a provider on rate limits, timeouts and server
 * errors with backoff; on a reply that fails validation, retry with the problems quoted back; on anything else move to
 * the next provider. If every provider fails, throw GenerationException::unavailable().
 */
final class ProviderManager
{
    /** @var array<string, LlmProvider> */
    private array $providers = [];

    /** @param  array<string, mixed>|null  $config */
    public function __construct(private ?array $config = null, private ?Closure $sleep = null, private ?GenerationLog $log = null)
    {
        $this->config ??= config('generation');
        $this->sleep ??= static fn (int $ms) => usleep($ms * 1000);
        $this->log ??= new GenerationLog;
    }

    public function provider(string $name): LlmProvider
    {
        if (! isset($this->providers[$name])) {
            $cfg = $this->config['providers'][$name] ?? null;
            if ($cfg === null) {
                throw new \InvalidArgumentException("Route names an unknown provider \"{$name}\".");
            }
            $this->providers[$name] = ProviderFactory::make($name, $cfg);
        }

        return $this->providers[$name];
    }

    /** @return list<string> */
    public function route(string $task): array
    {
        return array_values($this->config['routes'][$task] ?? []);
    }

    /**
     * @param  Closure(LlmResponse): list<string>|null  $validate  Returns problems with the reply, or an empty list
     * @param  list<string>  $avoidModels  "provider|model" keys to try last (used to find an independent verifier)
     */
    public function complete(string $task, LlmRequest $request, RunTally $tally, ?Closure $validate = null, array $avoidModels = []): LlmResponse
    {
        $this->assertBudgets($tally);
        $names = $this->route($task);
        // Preferred order: not tripped, not avoided; then avoided; tripped providers last.
        usort($names, fn ($a, $b) => $this->rank($a, $avoidModels) <=> $this->rank($b, $avoidModels));

        $failures = [];
        foreach ($names as $name) {
            $provider = $this->provider($name);
            if ($why = $provider->problem()) {
                $failures[] = "{$name}: {$why}";

                continue;
            }
            if ($this->tripped($name)) {
                $failures[] = "{$name}: temporarily skipped after repeated failures";

                continue;
            }
            $messages = $request->messages;
            for ($attempt = 1; $attempt <= 1 + (int) $this->config['retries']; $attempt++) {
                $tally->assertWithinBudget();
                $req = $attempt === 1 || $messages === $request->messages ? $request : $this->withMessages($request, $messages);
                try {
                    $response = $provider->complete($req);
                    $response->costUsd = $this->cost($provider, $response);
                    $tally->add($response);
                    $problems = $validate ? $validate($response) : [];
                    if ($problems !== []) {
                        $this->record($tally, $request, $provider, $attempt, $response, 'invalid_output', 'Reply did not fit the expected shape: '.implode('; ', array_slice($problems, 0, 5)));
                        $messages = array_merge($request->messages, [
                            ['role' => 'assistant', 'content' => mb_substr($response->text, 0, 4000)],
                            ['role' => 'user', 'content' => "That reply was not valid: ".implode('; ', array_slice($problems, 0, 8)).'. Reply again with only the corrected JSON object.'],
                        ]);
                        $failures[] = "{$name}: invalid output";

                        continue; // retry the same provider with the problems quoted back
                    }
                    $this->record($tally, $request, $provider, $attempt, $response);
                    $this->succeeded($name);

                    return $response;
                } catch (LlmException $e) {
                    $this->record($tally, $request, $provider, $attempt, null, $e->kind, $e->getMessage());
                    $failures[] = "{$name}: {$e->kind}";
                    if ($e->kind === LlmException::AUTH || $e->kind === LlmException::UNAVAILABLE) {
                        $this->failed($name, true);

                        break; // retrying a bad key will not help
                    }
                    if (! $e->retryable() || $attempt > (int) $this->config['retries']) {
                        $this->failed($name);

                        break;
                    }
                    ($this->sleep)($this->backoff($attempt, $e->retryAfterSeconds));
                }
            }
        }

        throw GenerationException::unavailable(['task' => $task, 'failures' => $failures]);
    }

    /** True when `$a` and `$b` are different (provider, model) pairs, so a second opinion is a real second opinion. */
    public static function independent(string $providerA, string $modelA, string $providerB, string $modelB): bool
    {
        return $providerA !== $providerB || $modelA !== $modelB;
    }

    /** @return array<string, array{ready:bool, problem:?string, model:string, driver:string, circuit_open:bool, routes:list<string>}> */
    public function health(): array
    {
        $out = [];
        foreach (array_keys($this->config['providers']) as $name) {
            $p = $this->provider($name);
            $out[$name] = [
                'ready' => $p->problem() === null, 'problem' => $p->problem(), 'model' => $p->model(),
                'driver' => (string) $this->config['providers'][$name]['driver'], 'circuit_open' => $this->tripped($name),
                'routes' => array_keys(array_filter($this->config['routes'], fn ($list) => in_array($name, $list, true))),
            ];
        }

        return $out;
    }

    private function assertBudgets(RunTally $tally): void
    {
        $tally->assertWithinBudget();
        if ($this->log->spentToday() >= (float) $this->config['budget']['daily_usd']) {
            throw GenerationException::budget('daily');
        }
    }

    private function rank(string $name, array $avoid): int
    {
        $score = $this->tripped($name) ? 2 : 0;
        $p = $this->providers[$name] ?? null;
        if ($p === null && isset($this->config['providers'][$name])) {
            $p = $this->provider($name);
        }
        if ($p && in_array($p->id().'|'.$p->model(), $avoid, true)) {
            $score += 1;
        }

        return $score;
    }

    private function withMessages(LlmRequest $r, array $messages): LlmRequest
    {
        return new LlmRequest($r->task, $r->system, $messages, $r->schema, $r->schemaName, $r->temperature, $r->maxTokens, $r->meta);
    }

    private function cost(LlmProvider $p, LlmResponse $r): float
    {
        $price = $p->price();

        return ($r->inputTokens * $price['in'] + $r->outputTokens * $price['out']) / 1_000_000;
    }

    private function backoff(int $attempt, ?int $retryAfter): int
    {
        $ms = (int) $this->config['backoff_ms'] * (2 ** ($attempt - 1)) + random_int(0, 250);

        return $retryAfter !== null ? max($ms, min($retryAfter, 30) * 1000) : $ms;
    }

    private function record(RunTally $tally, LlmRequest $request, LlmProvider $p, int $attempt, ?LlmResponse $r, ?string $kind = null, ?string $message = null): void
    {
        $this->log->call([
            'run_id' => $tally->runId, 'task' => $request->task, 'provider' => $p->id(), 'model' => $r->model ?? $p->model(), 'attempt' => $attempt,
            'ok' => $kind === null, 'error_kind' => $kind, 'error_message' => $message, 'latency_ms' => $r->latencyMs ?? 0,
            'input_tokens' => $r->inputTokens ?? 0, 'output_tokens' => $r->outputTokens ?? 0, 'cost_usd' => $r->costUsd ?? 0, 'finish_reason' => $r->finishReason ?? null,
            'request_hash' => $request->hash(),
            'payload' => $this->config['log_payloads'] && $r ? ['system' => $request->system, 'messages' => $request->messages, 'reply' => $r->text] : null,
        ]);
    }

    // Circuit breaker: `circuit_threshold` failures in a row open the circuit for `circuit_cooldown` seconds.
    private function tripped(string $name): bool
    {
        return (int) Cache::get("generation:circuit:{$name}:open_until", 0) > time();
    }

    private function failed(string $name, bool $immediate = false): void
    {
        $fails = (int) Cache::get("generation:circuit:{$name}:fails", 0) + 1;
        Cache::put("generation:circuit:{$name}:fails", $fails, 600);
        if ($immediate || $fails >= (int) $this->config['circuit_threshold']) {
            Cache::put("generation:circuit:{$name}:open_until", time() + (int) $this->config['circuit_cooldown'], 600);
            Cache::forget("generation:circuit:{$name}:fails");
        }
    }

    private function succeeded(string $name): void
    {
        Cache::forget("generation:circuit:{$name}:fails");
        Cache::forget("generation:circuit:{$name}:open_until");
    }
}
