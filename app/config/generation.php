<?php

// Content generation engine (docs/16-content-engine.md). Nothing here is learner-facing and nothing here
// publishes anything: generated content is quarantined, labelled and evidence-capped until a person reviews it.
//
// Providers are interchangeable. Every provider speaks the same internal contract (App\Modules\Generation\Contracts\LlmProvider);
// a "driver" is only the adapter to one kind of endpoint. Switching model or vendor is a change to this file or to .env.
$list = static fn (string $key, string $default): array => array_values(array_filter(array_map('trim', explode(',', (string) env($key, $default)))));

return [
    // Master switch. Off by default so no learner-facing path can reach a model until the operator opts in.
    'enabled' => (bool) env('GENERATION_ENABLED', false),

    /*
     | Providers. Add as many as you like under any name; `routes` decides which ones are used for what.
     |
     | driver:
     |   anthropic          Anthropic Messages API through the official PHP SDK.
     |   openai_compatible  Any server speaking POST {base_url}/chat/completions: OpenAI, Azure OpenAI, OpenRouter,
     |                      Groq, Together, Mistral, and local servers (Ollama, vLLM, LM Studio, llama.cpp server).
     |   http               Your own endpoint. We POST one normalised JSON request and expect one normalised JSON
     |                      response (see docs/16 §4.3). Use it for an in-house gateway or a model behind custom code.
     |   fake               Deterministic, offline, for tests and demos. Refuses to run in production.
     |
     | price: USD per million tokens, only used to estimate cost for budgets and the log. Local models are 0.
     */
    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('GENERATION_ANTHROPIC_BASE_URL'),
            'model' => env('GENERATION_ANTHROPIC_MODEL', 'claude-opus-5-5'),
            'effort' => env('GENERATION_ANTHROPIC_EFFORT', 'medium'),
            'timeout' => (int) env('GENERATION_ANTHROPIC_TIMEOUT', 180),
            'max_tokens' => 16000,
            'price' => ['in' => 4.0, 'out' => 20.0],
        ],
        // A second Claude model is the usual independent verifier: cheaper, and a different model.
        'anthropic_fast' => [
            'driver' => 'anthropic',
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('GENERATION_ANTHROPIC_BASE_URL'),
            'model' => env('GENERATION_ANTHROPIC_FAST_MODEL', 'claude-sonnet-5-5'),
            'effort' => 'medium',
            'timeout' => 120,
            'max_tokens' => 8000,
            'price' => ['in' => 2.0, 'out' => 10.0],
        ],
        'openai' => [
            'driver' => 'openai_compatible',
            'base_url' => env('GENERATION_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('GENERATION_OPENAI_MODEL'),
            'json_mode' => env('GENERATION_OPENAI_JSON_MODE', 'schema'), // schema | object | none
            'token_param' => 'max_completion_tokens',                     // older servers use max_tokens
            'timeout' => 180,
            'price' => ['in' => (float) env('GENERATION_OPENAI_PRICE_IN', 0), 'out' => (float) env('GENERATION_OPENAI_PRICE_OUT', 0)],
        ],
        'local' => [
            'driver' => 'openai_compatible',
            'base_url' => env('GENERATION_LOCAL_URL', 'http://127.0.0.1:11434/v1'),
            'api_key' => env('GENERATION_LOCAL_KEY'),
            'model' => env('GENERATION_LOCAL_MODEL'),
            'json_mode' => env('GENERATION_LOCAL_JSON_MODE', 'object'),
            'token_param' => 'max_tokens',
            'timeout' => (int) env('GENERATION_LOCAL_TIMEOUT', 600),
            'price' => ['in' => 0, 'out' => 0],
        ],
        'custom' => [
            'driver' => 'http',
            'url' => env('GENERATION_CUSTOM_URL'),
            'headers' => [], // e.g. ['Authorization' => 'Bearer '.env('GENERATION_CUSTOM_KEY')]
            'model' => env('GENERATION_CUSTOM_MODEL', 'custom'),
            'timeout' => 180,
            'price' => ['in' => 0, 'out' => 0],
        ],
        'fake' => ['driver' => 'fake', 'model' => 'fake-1', 'price' => ['in' => 0, 'out' => 0]],
    ],

    /*
     | Routes: for each task, an ordered list of providers. The first healthy one is used; on failure the next is tried.
     | `verify` should name a different model from `draft` (see require_independent_verifier).
     | Example: GENERATION_ROUTE_DRAFT=anthropic,openai,local  GENERATION_ROUTE_VERIFY=anthropic_fast,local
     */
    'routes' => [
        'plan' => $list('GENERATION_ROUTE_PLAN', 'fake'),
        'draft' => $list('GENERATION_ROUTE_DRAFT', 'fake'),
        'verify' => $list('GENERATION_ROUTE_VERIFY', 'fake'),
    ],

    // Resilience. A provider that fails `circuit_threshold` times in a row is skipped for `circuit_cooldown` seconds.
    'retries' => (int) env('GENERATION_RETRIES', 2),
    'backoff_ms' => 800,
    'circuit_threshold' => 3,
    'circuit_cooldown' => 60,

    // Quality. A mission is only kept if it passes every deterministic gate AND the blind solve agrees with its key.
    'max_repair_rounds' => 2,
    'solve_min_confidence' => 0.6,
    // Verification by the same model that wrote the content is weak. When true, content is only accepted if the
    // verifier is a different (provider, model) pair. In development it defaults to false so a single local model works.
    'require_independent_verifier' => (bool) env('GENERATION_REQUIRE_INDEPENDENT_VERIFIER', env('APP_ENV') === 'production'),

    // Scope and size of what a learner can ask for.
    'topic' => ['min_length' => 3, 'max_length' => 120],
    'roadmap' => ['min_topics' => 3, 'max_topics' => 6],
    // Topics matching these are refused before any model sees them. The model is also told the scope in its prompt.
    'topic_denylist' => [
        '/\b(malware|ransomware|keylogger|botnet|ddos|credential\s+stuffing|phishing\s+kit)\b.*\b(build|write|create|deploy|make)\b/i',
        '/\b(build|write|create|deploy|make)\b.*\b(malware|ransomware|keylogger|botnet|ddos|phishing\s+kit)\b/i',
        '/\b(bypass|crack|steal)\b.*\b(licen[sc]e|password|account|paywall)\b/i',
        '/\b(self[- ]?harm|suicide|bomb|weapon)\b/i',
    ],

    // Budgets (USD). Hard stops: a run that would exceed them fails, it is never silently truncated.
    'budget' => [
        'run_usd' => (float) env('GENERATION_RUN_BUDGET_USD', 3.0),
        'daily_usd' => (float) env('GENERATION_DAILY_BUDGET_USD', 50.0),
        'per_user_daily_runs' => (int) env('GENERATION_USER_DAILY_RUNS', 3),
    ],

    // Logging. Calls are always logged (provider, model, tokens, cost, latency, outcome). Prompts and raw replies are
    // only kept when payload logging is on, and are purged after `payload_retention_days`.
    'log_payloads' => (bool) env('GENERATION_LOG_PAYLOADS', false),
    'payload_retention_days' => 30,

    // Prompts are versioned. Bump when any prompt text changes so the log can tie quality to a prompt version.
    'prompt_version' => '2026-10-14.1',

    // Generated content is labelled and its evidence is capped at this level until a person reviews it (docs/16 §7).
    'unreviewed_evidence_cap' => 'practised',
];
