<?php

namespace App\Modules\Generation\Providers;

use App\Modules\Generation\Contracts\LlmProvider;
use App\Modules\Generation\Llm\LlmException;
use App\Modules\Generation\Llm\LlmRequest;
use App\Modules\Generation\Llm\LlmResponse;
use App\Modules\Generation\Support\JsonText;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Your own endpoint, for anything the other drivers do not cover (an in-house gateway, a model behind custom code).
 *
 * We POST:   {"task","system","messages":[{"role","content"}],"schema"|null,"schema_name","temperature","max_tokens"}
 * We expect: {"text":"...", "json":{...}|null, "model":"...", "usage":{"input_tokens":n,"output_tokens":n}, "finish_reason":"stop"}
 * Either "text" or "json" is enough. A non-2xx status is a failure (429 and 5xx are retried).
 */
final class HttpProvider implements LlmProvider
{
    /** @param  array<string, mixed>  $config */
    public function __construct(private string $id, private array $config) {}

    public function id(): string
    {
        return $this->id;
    }

    public function model(): string
    {
        return (string) ($this->config['model'] ?? 'custom');
    }

    public function price(): array
    {
        return ['in' => (float) ($this->config['price']['in'] ?? 0), 'out' => (float) ($this->config['price']['out'] ?? 0)];
    }

    public function problem(): ?string
    {
        return empty($this->config['url']) ? 'No endpoint URL is configured.' : null;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        if ($reason = $this->problem()) {
            throw new LlmException(LlmException::UNAVAILABLE, $reason);
        }
        $started = hrtime(true);
        try {
            $response = Http::timeout((int) ($this->config['timeout'] ?? 180))->acceptJson()->withHeaders((array) ($this->config['headers'] ?? []))->post((string) $this->config['url'], [
                'task' => $request->task,
                'system' => $request->system,
                'messages' => $request->messages,
                'schema' => $request->schema,
                'schema_name' => $request->schemaName,
                'temperature' => $request->temperature,
                'max_tokens' => $request->maxTokens,
            ]);
        } catch (ConnectionException $e) {
            $timedOut = str_contains(strtolower($e->getMessage()), 'timed out') || str_contains($e->getMessage(), 'cURL error 28');
            throw new LlmException($timedOut ? LlmException::TIMEOUT : LlmException::NETWORK, $timedOut ? 'The model took too long to answer.' : 'Could not reach the custom endpoint.');
        }
        $status = $response->status();
        if (! $response->successful()) {
            throw match (true) {
                $status === 429 => new LlmException(LlmException::RATE_LIMIT, 'Rate limited by the endpoint.', (int) $response->header('Retry-After') ?: null),
                in_array($status, [401, 403], true) => new LlmException(LlmException::AUTH, 'The endpoint rejected the credentials.'),
                $status >= 500 => new LlmException(LlmException::SERVER, "The endpoint had a server error ({$status})."),
                default => new LlmException(LlmException::BAD_REQUEST, "The endpoint rejected the request ({$status})."),
            };
        }
        $json = $response->json('json');
        $text = (string) ($response->json('text') ?? ($json !== null ? json_encode($json) : ''));
        if ($request->schema !== null && ! is_array($json)) {
            $json = JsonText::object($text);
        }
        if ($text === '' && $json === null) {
            throw new LlmException(LlmException::INVALID_OUTPUT, 'The endpoint returned neither text nor json.');
        }

        return new LlmResponse(
            text: $text,
            json: is_array($json) ? $json : null,
            provider: $this->id,
            model: (string) ($response->json('model') ?? $this->model()),
            inputTokens: (int) $response->json('usage.input_tokens', 0),
            outputTokens: (int) $response->json('usage.output_tokens', 0),
            latencyMs: (int) ((hrtime(true) - $started) / 1e6),
            finishReason: $response->json('finish_reason'),
        );
    }
}
