<?php

namespace App\Modules\Generation\Contracts;

use App\Modules\Generation\Llm\LlmException;
use App\Modules\Generation\Llm\LlmRequest;
use App\Modules\Generation\Llm\LlmResponse;

/**
 * The one thing the content engine needs from a model. Anything that can turn a request into text (a vendor API, a local
 * server, your own service) becomes a provider by implementing this. Throw LlmException, never a vendor exception.
 */
interface LlmProvider
{
    /** The configured name, for example "anthropic" or "local". */
    public function id(): string;

    /** The model identifier, used to decide whether a verifier is independent of a drafter. */
    public function model(): string;

    /** @return array{in:float, out:float} USD per million tokens, for cost estimates only */
    public function price(): array;

    /** Why this provider cannot be used right now (a missing key, model or URL), or null when it is ready. */
    public function problem(): ?string;

    /** @throws LlmException */
    public function complete(LlmRequest $request): LlmResponse;
}
