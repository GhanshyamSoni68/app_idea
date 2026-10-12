<?php

namespace App\Support;

use RuntimeException;

/** An error rendered in the standard envelope (docs/02 §5.4). The message is safe to display. */
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly bool $retryable = false,
        public readonly ?array $details = null,
        public readonly ?array $fields = null,
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $what = 'That item'): self
    {
        return new self('not_found', "{$what} was not found.", 404);
    }

    public static function conflict(string $code, string $message, ?array $details = null): self
    {
        return new self($code, $message, 409, false, $details);
    }

    public static function unauthenticated(): self
    {
        return new self('unauthenticated', 'Please sign in to continue.', 401);
    }
}
