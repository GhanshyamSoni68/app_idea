<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/**
 * The application's single source of "now" (docs/12 EP01: a controllable clock outside production).
 * `php artisan clock:set 2026-10-13T09:00:00Z` shifts it in local, test and preview environments;
 * production ignores the override file entirely.
 */
final class Clock
{
    private static ?CarbonImmutable $frozen = null;

    public static function now(): CarbonImmutable
    {
        if (self::$frozen !== null) {
            return self::$frozen;
        }

        $override = self::overrideInstant();

        return $override ?? CarbonImmutable::now('UTC');
    }

    /** Freeze the clock in-process (tests). Pass null to unfreeze. */
    public static function freeze(?string $instant): void
    {
        self::$frozen = $instant === null ? null : CarbonImmutable::parse($instant, 'UTC');
    }

    public static function overridePath(): string
    {
        return storage_path('framework/devstep-clock');
    }

    private static function overrideInstant(): ?CarbonImmutable
    {
        if (app()->isProduction()) {
            return null;
        }
        $path = self::overridePath();
        if (! is_file($path)) {
            return null;
        }
        $raw = trim((string) File::get($path));

        return $raw === '' ? null : CarbonImmutable::parse($raw, 'UTC');
    }
}
