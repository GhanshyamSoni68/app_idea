<?php

namespace App\Modules\Generation\Providers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Core\Exceptions\UnprocessableEntityException;
use App\Modules\Generation\Contracts\LlmProvider;
use App\Modules\Generation\Llm\LlmException;
use App\Modules\Generation\Llm\LlmRequest;
use App\Modules\Generation\Llm\LlmResponse;
use App\Modules\Generation\Support\JsonText;
use Closure;

/**
 * Claude through the official Anthropic PHP SDK. The SDK's own retries are off (maxRetries 0): retry, backoff and
 * fallback are the ProviderManager's job, so behaviour is the same whichever provider answers.
 *
 * Sampling parameters (temperature and friends) are deliberately not sent: current Claude models reject non-default
 * values. Depth is controlled with `effort`, set per provider in config/generation.php.
 */
final class AnthropicProvider implements LlmProvider
{
    private ?object $client = null;

    /** @param  array<string, mixed>  $config */
    public function __construct(private string $id, private array $config, private ?Closure $clientFactory = null) {}

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
        if (empty($this->config['api_key']) && $this->clientFactory === null) {
            return 'ANTHROPIC_API_KEY is not set.';
        }

        return $this->model() === '' ? 'No model is configured.' : null;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        if ($reason = $this->problem()) {
            throw new LlmException(LlmException::UNAVAILABLE, $reason);
        }
        $started = hrtime(true);
        $output = [];
        if (! empty($this->config['effort'])) {
            $output['effort'] = $this->config['effort'];
        }
        if ($request->schema !== null) {
            $output['format'] = ['type' => 'json_schema', 'schema' => $request->schema];
        }
        $args = [
            'model' => $this->model(),
            'maxTokens' => min($request->maxTokens, (int) ($this->config['max_tokens'] ?? 16000)),
            'system' => $request->system,
            'messages' => $request->messages,
        ];
        if ($output !== []) {
            $args['outputConfig'] = $output;
        }

        try {
            $message = $this->client()->messages->create(...$args);
        } catch (APITimeoutException $e) {
            throw new LlmException(LlmException::TIMEOUT, 'The model took too long to answer.');
        } catch (APIConnectionException $e) {
            throw new LlmException(LlmException::NETWORK, 'Could not reach the model: '.$e->getMessage());
        } catch (RateLimitException $e) {
            throw new LlmException(LlmException::RATE_LIMIT, 'Rate limited by the provider.');
        } catch (AuthenticationException|PermissionDeniedException $e) {
            throw new LlmException(LlmException::AUTH, 'The provider rejected the credentials.');
        } catch (BadRequestException|UnprocessableEntityException $e) {
            throw new LlmException(LlmException::BAD_REQUEST, 'The provider rejected the request: '.$this->brief($e));
        } catch (InternalServerException $e) {
            throw new LlmException(LlmException::SERVER, 'The provider had a server error.');
        } catch (APIStatusException $e) {
            // 529 "overloaded" and other 5xx are worth retrying; other statuses are not.
            throw new LlmException(($e->status ?? 0) >= 500 ? LlmException::SERVER : LlmException::BAD_REQUEST, 'Provider error '.($e->status ?? '?').': '.$this->brief($e));
        } catch (APIException $e) {
            throw new LlmException(LlmException::NETWORK, 'Provider call failed: '.$this->brief($e));
        }

        if (($message->stopReason ?? null) === 'refusal') {
            throw new LlmException(LlmException::REFUSAL, 'The model declined this request.');
        }
        $text = '';
        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'text') {
                $text .= $block->text;
            }
        }
        if (($message->stopReason ?? null) === 'max_tokens') {
            throw new LlmException(LlmException::TRUNCATED, 'The reply was cut off at the token limit.');
        }

        return new LlmResponse(
            text: $text,
            json: $request->schema !== null ? JsonText::object($text) : null,
            provider: $this->id,
            model: $this->model(),
            inputTokens: (int) ($message->usage->inputTokens ?? 0),
            outputTokens: (int) ($message->usage->outputTokens ?? 0),
            latencyMs: (int) ((hrtime(true) - $started) / 1e6),
            finishReason: $message->stopReason ?? null,
        );
    }

    private function client(): object
    {
        if ($this->client === null) {
            $this->client = $this->clientFactory
                ? ($this->clientFactory)($this->config)
                : new Client(
                    apiKey: (string) $this->config['api_key'],
                    baseUrl: $this->config['base_url'] ?: null,
                    requestOptions: ['timeout' => (float) ($this->config['timeout'] ?? 180), 'maxRetries' => 0],
                );
        }

        return $this->client;
    }

    private function brief(\Throwable $e): string
    {
        return mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 300);
    }
}
