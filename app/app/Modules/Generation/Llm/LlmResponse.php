<?php

namespace App\Modules\Generation\Llm;

final class LlmResponse
{
    /**
     * @param  array<string, mixed>|null  $json  The parsed reply when a schema was requested
     */
    public function __construct(
        public readonly string $text,
        public readonly ?array $json,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $latencyMs = 0,
        public readonly ?string $finishReason = null,
        public float $costUsd = 0.0,
    ) {}
}
