<?php

namespace App\Modules\Generation;

use App\Modules\Generation\Contracts\LlmProvider;
use App\Modules\Generation\Providers\AnthropicProvider;
use App\Modules\Generation\Providers\FakeProvider;
use App\Modules\Generation\Providers\HttpProvider;
use App\Modules\Generation\Providers\OpenAiCompatibleProvider;
use InvalidArgumentException;

/** Turns one entry of config('generation.providers') into a provider. A new vendor is one new driver and one line here. */
final class ProviderFactory
{
    /** @param  array<string, mixed>  $config */
    public static function make(string $name, array $config): LlmProvider
    {
        return match ($config['driver'] ?? null) {
            'anthropic' => new AnthropicProvider($name, $config),
            'openai_compatible' => new OpenAiCompatibleProvider($name, $config),
            'http' => new HttpProvider($name, $config),
            'fake' => new FakeProvider($name, $config),
            default => throw new InvalidArgumentException("Provider \"{$name}\" has an unknown driver \"".($config['driver'] ?? '').'".'),
        };
    }
}
