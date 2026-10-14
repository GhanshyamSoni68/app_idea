<?php

namespace App\Modules\Generation\Llm;

/**
 * One model call, in a vendor-neutral shape. Providers translate it; nothing outside a provider knows a vendor's API.
 * The JSON Schema is restricted to the subset every vendor's structured output accepts (see Support\Schemas).
 */
final class LlmRequest
{
    /**
     * @param  list<array{role:string, content:string}>  $messages
     * @param  array<string, mixed>|null  $schema  JSON Schema for the reply, or null for free text
     * @param  array<string, mixed>  $meta  Never sent to a vendor. Used for logging, and by the offline fake provider.
     */
    public function __construct(
        public readonly string $task,
        public readonly string $system,
        public readonly array $messages,
        public readonly ?array $schema = null,
        public readonly string $schemaName = 'result',
        public readonly float $temperature = 0.4,
        public readonly int $maxTokens = 8000,
        public readonly array $meta = [],
    ) {}

    public function hash(): string
    {
        return substr(hash('sha256', json_encode([$this->task, $this->system, $this->messages, $this->schema])), 0, 16);
    }
}
