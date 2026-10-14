<?php

namespace App\Modules\Generation\Llm;

use RuntimeException;

/** Every provider failure becomes one of these kinds, so routing and retry never depend on a vendor's exception types. */
final class LlmException extends RuntimeException
{
    public const RATE_LIMIT = 'rate_limit';

    public const TIMEOUT = 'timeout';

    public const NETWORK = 'network';

    public const SERVER = 'server';

    public const AUTH = 'auth';

    public const BAD_REQUEST = 'bad_request';

    public const REFUSAL = 'refusal';

    public const TRUNCATED = 'truncated';

    public const INVALID_OUTPUT = 'invalid_output';

    public const UNAVAILABLE = 'unavailable';

    public function __construct(public readonly string $kind, string $message, public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct($message);
    }

    /** Worth trying the same provider again after a pause. Everything else moves on to the next provider (or stops). */
    public function retryable(): bool
    {
        return in_array($this->kind, [self::RATE_LIMIT, self::TIMEOUT, self::NETWORK, self::SERVER], true);
    }
}
