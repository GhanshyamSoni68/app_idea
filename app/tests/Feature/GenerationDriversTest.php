<?php

namespace Tests\Feature;

use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use App\Modules\Generation\Llm\LlmException;
use App\Modules\Generation\Llm\LlmRequest;
use App\Modules\Generation\ProviderFactory;
use App\Modules\Generation\Providers\AnthropicProvider;
use App\Modules\Generation\Providers\HttpProvider;
use App\Modules\Generation\Providers\OpenAiCompatibleProvider;
use App\Modules\Generation\Support\Schemas;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Each driver turns the one internal request into its endpoint's format, and every failure into one of our kinds. */
class GenerationDriversTest extends TestCase
{
    private function req(?array $schema = null): LlmRequest
    {
        return new LlmRequest('plan', 'You plan.', [['role' => 'user', 'content' => 'Plan it.']], $schema, 'thing', 0.3, 500);
    }

    private function kind(callable $f): string
    {
        try {
            $f();
        } catch (LlmException $e) {
            return $e->kind;
        }

        return 'none';
    }

    private function openai(array $over = []): OpenAiCompatibleProvider
    {
        return new OpenAiCompatibleProvider('oa', $over + ['base_url' => 'https://oa.test/v1', 'api_key' => 'k', 'model' => 'm1', 'json_mode' => 'schema', 'token_param' => 'max_completion_tokens', 'price' => ['in' => 1, 'out' => 2]]);
    }

    /** A fresh fake every time: Laravel keeps the first stub that matches, so re-faking would not replace it. */
    private function fake(array $stubs): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake($stubs);
    }

    private function chat(mixed $content, array $extra = []): array
    {
        return ['model' => 'm1-2026', 'choices' => [array_merge(['message' => ['content' => $content], 'finish_reason' => 'stop'], $extra)], 'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 7]];
    }

    // ---- OpenAI-compatible: OpenAI, Azure, OpenRouter, and local servers -------------------------------------------

    public function test_openai_compatible_strict_schema_mode(): void
    {
        $this->fake(['oa.test/*' => Http::response($this->chat('{"a":1}'))]);
        $r = $this->openai()->complete($this->req(['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'required' => ['a'], 'additionalProperties' => false]));

        Http::assertSent(function (HttpRequest $h) {
            $b = $h->data();

            return $h->url() === 'https://oa.test/v1/chat/completions' && $h->hasHeader('Authorization', 'Bearer k')
                && $b['model'] === 'm1' && $b['max_completion_tokens'] === 500 && ! isset($b['max_tokens'])
                && $b['messages'][0] === ['role' => 'system', 'content' => 'You plan.'] && $b['messages'][1]['content'] === 'Plan it.'
                && $b['response_format']['type'] === 'json_schema' && $b['response_format']['json_schema']['strict'] === true;
        });
        $this->assertSame(['a' => 1], $r->json);
        $this->assertSame([11, 7, 'm1-2026'], [$r->inputTokens, $r->outputTokens, $r->model]);
    }

    public function test_a_local_server_without_a_key_uses_json_mode_and_the_schema_in_the_prompt(): void
    {
        $this->fake(['127.0.0.1:11434/*' => Http::response($this->chat("<think>hmm</think>\n```json\n{\"a\":2}\n```"))]);
        $p = new OpenAiCompatibleProvider('local', ['base_url' => 'http://127.0.0.1:11434/v1', 'model' => 'llama', 'json_mode' => 'object', 'token_param' => 'max_tokens']);
        $r = $p->complete($this->req(['type' => 'object']));

        Http::assertSent(function (HttpRequest $h) {
            $b = $h->data();

            return ! $h->hasHeader('Authorization') && $b['max_tokens'] === 500 && $b['response_format'] === ['type' => 'json_object']
                && str_contains($b['messages'][0]['content'], 'Reply with ONE JSON object');
        });
        $this->assertSame(['a' => 2], $r->json, 'reasoning tags and code fences are stripped');
        $this->assertSame(0.0, $p->price()['in']);
    }

    public function test_json_mode_none_sends_no_response_format_and_azure_style_query_and_headers_are_supported(): void
    {
        $this->fake(['az.test/*' => Http::response($this->chat([['type' => 'text', 'text' => '{"a":'], ['type' => 'text', 'text' => '3}']]))]);
        $p = $this->openai(['base_url' => 'https://az.test/openai/deployments/d', 'json_mode' => 'none', 'query' => ['api-version' => '2024-06-01'], 'headers' => ['api-key' => 'azure-secret'], 'api_key' => null]);
        $r = $p->complete($this->req(['type' => 'object']));

        Http::assertSent(fn (HttpRequest $h) => str_contains($h->url(), '/chat/completions?api-version=2024-06-01') && $h->hasHeader('api-key', 'azure-secret') && ! isset($h->data()['response_format']));
        $this->assertSame(['a' => 3], $r->json, 'content parts are joined');
    }

    public function test_openai_compatible_failures_become_our_kinds(): void
    {
        $cases = [
            'auth' => Http::response(['error' => ['message' => 'no']], 401),
            'rate_limit' => Http::response('', 429, ['Retry-After' => '7']),
            'server' => Http::response('', 502),
            'bad_request' => Http::response(['error' => ['message' => 'bad schema']], 400),
            'timeout' => Http::response('', 408),
        ];
        foreach ($cases as $kind => $reply) {
            $this->fake(['oa.test/*' => $reply]);
            $this->assertSame($kind, $this->kind(fn () => $this->openai()->complete($this->req())), $kind);
        }
        $this->fake(['oa.test/*' => Http::response('', 429, ['Retry-After' => '7'])]);
        try {
            $this->openai()->complete($this->req());
        } catch (LlmException $e) {
            $this->assertSame(7, $e->retryAfterSeconds);
            $this->assertTrue($e->retryable());
        }
        $this->fake(['oa.test/*' => Http::response($this->chat('cut', ['finish_reason' => 'length']) + [], 200)]);
        $this->assertSame('truncated', $this->kind(fn () => $this->openai()->complete($this->req())));
        $this->fake(['oa.test/*' => Http::response(['choices' => [['message' => ['content' => '', 'refusal' => 'I cannot'], 'finish_reason' => 'stop']]])]);
        $this->assertSame('refusal', $this->kind(fn () => $this->openai()->complete($this->req())));
        $this->fake(['oa.test/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);
        $this->assertSame('timeout', $this->kind(fn () => $this->openai()->complete($this->req())));
        $this->fake(['oa.test/*' => fn () => throw new ConnectionException('cURL error 7: Failed to connect')]);
        $this->assertSame('network', $this->kind(fn () => $this->openai()->complete($this->req())));
        $this->assertSame('unavailable', $this->kind(fn () => (new OpenAiCompatibleProvider('x', ['base_url' => 'https://x', 'model' => '']))->complete($this->req())));
    }

    // ---- Custom HTTP -----------------------------------------------------------------------------------------------

    public function test_the_custom_endpoint_contract(): void
    {
        $this->fake(['gw.test/*' => Http::response(['json' => ['ok' => true], 'model' => 'in-house-7', 'usage' => ['input_tokens' => 5, 'output_tokens' => 6], 'finish_reason' => 'stop'])]);
        $p = new HttpProvider('custom', ['url' => 'https://gw.test/complete', 'headers' => ['X-Team' => 'content'], 'model' => 'custom']);
        $r = $p->complete($this->req(['type' => 'object']));

        Http::assertSent(function (HttpRequest $h) {
            $b = $h->data();

            return $h->hasHeader('X-Team', 'content') && $b['task'] === 'plan' && $b['system'] === 'You plan.' && $b['messages'][0]['role'] === 'user'
                && $b['schema'] === ['type' => 'object'] && $b['max_tokens'] === 500 && $b['schema_name'] === 'thing' && ! isset($b['meta']);
        });
        $this->assertSame(['ok' => true], $r->json);
        $this->assertSame(['in-house-7', 5, 6], [$r->model, $r->inputTokens, $r->outputTokens]);

        $this->fake(['gw.test/*' => Http::response(['text' => 'Result: {"ok":false}'])]);
        $this->assertSame(['ok' => false], $p->complete($this->req(['type' => 'object']))->json, 'JSON is found in text when no json field is given');
        $this->fake(['gw.test/*' => Http::response([])]);
        $this->assertSame('invalid_output', $this->kind(fn () => $p->complete($this->req())));
        $this->fake(['gw.test/*' => Http::response('', 429)]);
        $this->assertSame('rate_limit', $this->kind(fn () => $p->complete($this->req())));
        $this->fake(['gw.test/*' => Http::response('', 503)]);
        $this->assertSame('server', $this->kind(fn () => $p->complete($this->req())));
        $this->assertSame('unavailable', $this->kind(fn () => (new HttpProvider('c', []))->complete($this->req())));
    }

    // ---- Anthropic (official PHP SDK) ------------------------------------------------------------------------------

    private function stubClient(object $message, ?callable $throw = null): array
    {
        $log = new \stdClass;
        $log->args = null;
        $client = new class($message, $log, $throw)
        {
            public object $messages;

            public function __construct($message, $log, $throw)
            {
                $this->messages = new class($message, $log, $throw)
                {
                    public function __construct(private $m, private $log, private $throw) {}

                    public function create(...$args)
                    {
                        $this->log->args = $args;
                        if ($this->throw) {
                            ($this->throw)();
                        }

                        return $this->m;
                    }
                };
            }
        };

        return [$client, $log];
    }

    private function message(string $stop = 'end_turn', string $text = '{"x":1}'): object
    {
        return (object) ['stopReason' => $stop, 'content' => [(object) ['type' => 'thinking', 'thinking' => ''], (object) ['type' => 'text', 'text' => $text]], 'usage' => (object) ['inputTokens' => 30, 'outputTokens' => 9]];
    }

    private function anthropic(object $client, array $over = []): AnthropicProvider
    {
        return new AnthropicProvider('anthropic', $over + ['api_key' => 'k', 'model' => 'claude-opus-5-5', 'effort' => 'medium', 'max_tokens' => 4000, 'price' => ['in' => 4, 'out' => 20]], fn () => $client);
    }

    public function test_anthropic_request_shape_and_response_mapping(): void
    {
        [$client, $log] = $this->stubClient($this->message());
        $r = $this->anthropic($client)->complete($this->req(['type' => 'object']));

        $this->assertSame('claude-opus-5-5', $log->args['model']);
        $this->assertSame(500, $log->args['maxTokens']);
        $this->assertSame('You plan.', $log->args['system']);
        $this->assertSame([['role' => 'user', 'content' => 'Plan it.']], $log->args['messages']);
        $this->assertSame('medium', $log->args['outputConfig']['effort']);
        $this->assertSame('json_schema', $log->args['outputConfig']['format']['type']);
        $this->assertArrayNotHasKey('temperature', $log->args, 'current Claude models reject sampling parameters');
        $this->assertArrayNotHasKey('thinking', $log->args);
        $this->assertSame(['x' => 1], $r->json, 'thinking blocks are skipped');
        $this->assertSame([30, 9], [$r->inputTokens, $r->outputTokens]);

        [$client, $log] = $this->stubClient($this->message('end_turn', 'free text'));
        $r = $this->anthropic($client, ['effort' => null, 'max_tokens' => 100])->complete($this->req());
        $this->assertSame(100, $log->args['maxTokens'], 'the provider cap wins over a larger request');
        $this->assertArrayNotHasKey('outputConfig', $log->args);
        $this->assertNull($r->json);
        $this->assertSame('free text', $r->text);
    }

    public function test_anthropic_stop_reasons_and_errors_become_our_kinds(): void
    {
        $this->assertSame('refusal', $this->kind(fn () => $this->anthropic($this->stubClient($this->message('refusal'))[0])->complete($this->req())));
        $this->assertSame('truncated', $this->kind(fn () => $this->anthropic($this->stubClient($this->message('max_tokens'))[0])->complete($this->req())));
        $this->assertSame('unavailable', $this->kind(fn () => (new AnthropicProvider('a', ['model' => 'm']))->complete($this->req())));

        $request = new Request('POST', 'https://api.anthropic.com/v1/messages');
        $status = fn (int $code) => fn () => throw APIStatusException::from($request, new Response($code, [], json_encode(['type' => 'error', 'error' => ['type' => 'x', 'message' => 'm']])));
        foreach ([429 => 'rate_limit', 401 => 'auth', 403 => 'auth', 400 => 'bad_request', 422 => 'bad_request', 500 => 'server', 529 => 'server', 418 => 'bad_request'] as $code => $kind) {
            $this->assertSame($kind, $this->kind(fn () => $this->anthropic($this->stubClient($this->message(), $status($code))[0])->complete($this->req())), (string) $code);
        }
        $this->assertSame('timeout', $this->kind(fn () => $this->anthropic($this->stubClient($this->message(), fn () => throw new APITimeoutException($request))[0])->complete($this->req())));
        $this->assertSame('network', $this->kind(fn () => $this->anthropic($this->stubClient($this->message(), fn () => throw new APIConnectionException($request))[0])->complete($this->req())));
    }

    /** The real SDK, against a local mock of the API: proves the wire format without a key. */
    public function test_the_real_anthropic_sdk_sends_the_expected_request(): void
    {
        $sock = @stream_socket_server('tcp://127.0.0.1:0');
        if (! $sock) {
            $this->markTestSkipped('cannot bind a local port');
        }
        $port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        $out = tempnam(sys_get_temp_dir(), 'anthropic-mock');
        $server = new Process([PHP_BINARY, '-S', "127.0.0.1:{$port}", base_path('tests/Support/anthropic_mock.php')], null, ['MOCK_OUT' => $out, 'MOCK_MODE' => 'ok']);
        $server->start();
        try {
            for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port); $i++) {
                usleep(100_000);
            }
            $provider = ProviderFactory::make('anthropic', ['driver' => 'anthropic', 'api_key' => 'sk-test-123', 'base_url' => "http://127.0.0.1:{$port}", 'model' => 'claude-opus-5-5', 'effort' => 'medium', 'timeout' => 20, 'max_tokens' => 4000, 'price' => ['in' => 4, 'out' => 20]]);
            $r = $provider->complete(new LlmRequest('critique', 'Review it.', [['role' => 'user', 'content' => 'The mission.']], Schemas::critique(), 'review', 0.0, 900));

            $seen = json_decode(file_get_contents($out), true);
            $this->assertSame(['POST', '/v1/messages'], [$seen['method'], $seen['path']]);
            $this->assertSame('sk-test-123', $seen['headers']['x-api-key']);
            $this->assertArrayHasKey('anthropic-version', $seen['headers']);
            $b = $seen['body'];
            $this->assertSame(['claude-opus-5-5', 900, 'Review it.'], [$b['model'], $b['max_tokens'], $b['system']]);
            $this->assertSame([['role' => 'user', 'content' => 'The mission.']], $b['messages']);
            $this->assertSame('medium', $b['output_config']['effort']);
            $this->assertSame('json_schema', $b['output_config']['format']['type']);
            $this->assertSame(Schemas::critique(), $b['output_config']['format']['schema']);
            $this->assertArrayNotHasKey('temperature', $b);
            $this->assertSame('pass', $r->json['verdict']);
            $this->assertSame([321, 45], [$r->inputTokens, $r->outputTokens]);
        } finally {
            $server->stop();
            @unlink($out);
        }
    }

    public function test_the_real_sdk_surfaces_rate_limits_and_bad_keys_as_our_kinds(): void
    {
        $sock = @stream_socket_server('tcp://127.0.0.1:0');
        if (! $sock) {
            $this->markTestSkipped('cannot bind a local port');
        }
        $port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        foreach (['rate_limit' => 'rate_limit', 'auth' => 'auth', 'refusal' => 'refusal', 'max_tokens' => 'truncated'] as $mode => $kind) {
            $out = tempnam(sys_get_temp_dir(), 'anthropic-mock');
            $server = new Process([PHP_BINARY, '-S', "127.0.0.1:{$port}", base_path('tests/Support/anthropic_mock.php')], null, ['MOCK_OUT' => $out, 'MOCK_MODE' => $mode]);
            $server->start();
            try {
                for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port); $i++) {
                    usleep(100_000);
                }
                $p = new AnthropicProvider('a', ['api_key' => 'k', 'base_url' => "http://127.0.0.1:{$port}", 'model' => 'claude-opus-5-5', 'timeout' => 20]);
                $this->assertSame($kind, $this->kind(fn () => $p->complete($this->req())), $mode);
            } finally {
                $server->stop();
                @unlink($out);
            }
        }
    }

    public function test_the_factory_rejects_an_unknown_driver_and_every_configured_driver_builds(): void
    {
        foreach (config('generation.providers') as $name => $cfg) {
            $this->assertSame($name, ProviderFactory::make($name, $cfg)->id());
        }
        $this->expectException(\InvalidArgumentException::class);
        ProviderFactory::make('x', ['driver' => 'carrier-pigeon']);
    }
}
