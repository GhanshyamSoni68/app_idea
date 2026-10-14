<?php

namespace App\Modules\Generation;

use App\Modules\Generation\Llm\LlmResponse;

/** What one run has spent so far, so a run can be stopped at its budget instead of after it. */
final class RunTally
{
    public float $costUsd = 0.0;

    public int $inputTokens = 0;

    public int $outputTokens = 0;

    public int $calls = 0;

    public function __construct(public readonly ?string $runId = null, public readonly ?float $budgetUsd = null) {}

    public function add(LlmResponse $r): void
    {
        $this->costUsd += $r->costUsd;
        $this->inputTokens += $r->inputTokens;
        $this->outputTokens += $r->outputTokens;
        $this->calls++;
    }

    public function assertWithinBudget(): void
    {
        if ($this->budgetUsd !== null && $this->costUsd >= $this->budgetUsd) {
            throw GenerationException::budget('run');
        }
    }
}
