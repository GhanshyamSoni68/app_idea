<?php

namespace App\Modules\Generation;

use RuntimeException;

/**
 * A generation run that ended without usable content. `code` is machine-readable (stored on the run); `getMessage()` is a
 * plain sentence that is safe to show a learner and never names a vendor, a model or a prompt.
 */
final class GenerationException extends RuntimeException
{
    /** @param  array<string, mixed>  $detail  For the log only, never shown to a learner */
    public function __construct(public readonly string $code_, string $message, public readonly array $detail = [])
    {
        parent::__construct($message);
    }

    public static function disabled(): self
    {
        return new self('disabled', 'Creating a path on your own topic is not switched on yet.');
    }

    public static function rejectedTopic(string $why): self
    {
        return new self('topic_rejected', $why);
    }

    public static function budget(string $which): self
    {
        return new self("budget_{$which}", 'We have reached the limit for creating new paths for now. Please try again later.', ['limit' => $which]);
    }

    public static function unavailable(array $detail = []): self
    {
        return new self('unavailable', 'We could not create that path right now. Nothing was changed. Please try again later.', $detail);
    }

    /** @param  list<array{severity:string,code:string,path:string,message:string}>|list<string>  $findings */
    public static function notGoodEnough(array $findings): self
    {
        return new self('quality', 'We could not make a path on that topic that met our quality checks, so we did not keep it. Try rewording the topic, or choose a narrower one.', ['findings' => $findings]);
    }
}
