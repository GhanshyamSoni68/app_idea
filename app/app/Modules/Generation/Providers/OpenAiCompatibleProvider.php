<?php

namespace App\Modules\Generation\Providers;

use App\Modules\Generation\Contracts\LlmProvider;
use App\Modules\Generation\Llm\LlmException;
use App\Modules\Generation\Llm\LlmRequest;
use App\Modules\Generation\Llm\LlmResponse;
use App\Modules\Generation\Support\JsonText;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Any server that speaks POST {base_url}/chat/completions: OpenAI, Azure OpenAI, OpenRouter, Groq, Together, Mistral,
 * and local servers (Ollama, vLLM, LM Studio, llama.cpp). This one adapter is how "local" and "third party" both work.
 *
 * json_mode says how to ask for JSON, because servers differ:
 *   schema  strict structured output (OpenAI and compatible)
 *   object  JSON mode, with the schema described in the prompt (most local servers)
 *   none    plain text; the schema is described in the prompt and the reply is parsed from the text
 */
final class OpenAiCompatibleProvider implements LlmProvider
{
    /** @param  array<string, mixed>  $config */
    public function __construct(private string $id, private array $config) {}

    public function id(): string
    {
        return $this->id;
    }

    public function model(): string
    {
        return (string) ($this->config['model'] ?? '');
    }

    public function price(): array
    {
        return ['in' => (float) ($this->config['price']['in'] ?? 0), 'out' => (float) ($this->config['price']['out'] ?? 0)];
    }

    public function problem(): ?string
    {
        if (empty($this->config['base_url'])) {
            return 'No base URL is configured.';
        }

        return $this->model() === '' ? 'No model is configured.' : null;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        if ($reason = $this->problem()) {
            throw new LlmException(LlmException::UNAVAILABLE, $reason);
        }
        $mode = (string) ($this->config['json_mode'] ?? 'object');
        $system = $request->system;
        $payload = [
            'model' => $this->model(),
            'temperature' => $request->temperature,
            ($this->config['token_param'] ?? 'max_tokens') => $request->maxTokens,
        ];
        if ($request->schema !== null) {
            if ($mode === 'schema') {
                $payload['response_format'] = ['type' => 'json_schema', 'json_schema' => ['name' => $request->schemaName, 'strict' => true, 'schema' => $request->schema]];
            } else {
                if ($mode === 'object') {
                    $payload['response_format'] = ['type' => 'json_object'];
                }
                $system .= "\n\nReply with ONE JSON object and nothing else, matching this JSON Schema:\n".json_encode($request->schema);
            }
        }
        $payload['messages'] = array_merge([['role' => 'system', 'content' => $system]], $request->messages);

        $started = hrtime(true);
        try {
            $http = Http::timeout((int) ($this->config['timeout'] ?? 180))->acceptJson()->withHeaders((array) ($this->config['headers'] ?? []));
            if (! empty($this->config['api_key'])) {
                $http = $http->withToken((string) $this->config['api_key']);
            }
            $url = rtrim((string) $this->config['base_url'], '/').'/chat/completions';
            if (! empty($this->config['query'])) {
                $url .= '?'.http_build_query((array) $this->config['query']);
            }
            $response = $http->post($url, $payload);
        } catch (ConnectionException $e) {
            $timedOut = str_contains(strtolower($e->getMessage()), 'timed out') || str_contains($e->getMessage(), 'cURL error 28');
            throw new LlmException($timedOut ? LlmException::TIMEOUT : LlmException::NETWORK, $timedOut ? 'The model took too long to answer.' : 'Could not reach the model server.');
        }
        $this->failIfError($response);

        $choice = $response->json('choices.0') ?? [];
        $message = $choice['message'] ?? [];
        if (! empty($message['refusal']) || ($choice['finish_reason'] ?? null) === 'content_filter') {
            throw new LlmException(LlmException::REFUSAL, 'The model declined this request.');
        }
        $text = $this->text($message['content'] ?? '');
        if (($choice['finish_reason'] ?? null) === 'length') {
            throw new LlmException(LlmException::TRUNCATED, 'The reply was cut off at the token limit.');
        }

        return new LlmResponse(
            text: $text,
            json: $request->schema !== null ? JsonText::object($text) : null,
            provider: $this->id,
            model: (string) ($response->json('model') ?? $this->model()),
            inputTokens: (int) $response->json('usage.prompt_tokens', 0),
            outputTokens: (int) $response->json('usage.completion_tokens', 0),
            latencyMs: (int) ((hrtime(true) - $started) / 1e6),
            finishReason: $choice['finish_reason'] ?? null,
        );
    }

    private function failIfError(Response $r): void
    {
        if ($r->successful()) {
            return;
        }
        $detail = mb_substr(preg_replace('/\s+/', ' ', (string) ($r->json('error.message') ?? $r->body())) ?? '', 0, 300);
        $status = $r->status();
        throw match (true) {
            $status === 429 => new LlmException(LlmException::RATE_LIMIT, 'Rate limited by the provider.', (int) $r->header('Retry-After') ?: null),
            in_array($status, [401, 403], true) => new LlmException(LlmException::AUTH, 'The provider rejected the credentials.'),
            $status === 408 => new LlmException(LlmException::TIMEOUT, 'The model took too long to answer.'),
            $status >= 500 => new LlmException(LlmException::SERVER, "The provider had a server error ({$status})."),
            default => new LlmException(LlmException::BAD_REQUEST, "The provider rejected the request ({$status}): {$detail}"),
        };
    }

    /** Content is a string, or a list of parts on some servers. Reasoning models may wrap thinking in <think> tags. */
    private function text(mixed $content): string
    {
        if (is_array($content)) {
            $content = implode('', array_map(fn ($p) => is_array($p) ? ($p['text'] ?? '') : (string) $p, $content));
        }

        return trim(preg_replace('#<think>.*?</think>#s', '', (string) $content) ?? '');
    }
}
