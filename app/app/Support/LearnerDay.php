<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * "Learner day" (docs/05 §2): the local calendar date in the learner's IANA zone after
 * subtracting `day_rollover_hour`. A session at 00:30 on Wednesday belongs to Tuesday.
 */
final class LearnerDay
{
    public static function of(CarbonImmutable $instant, string $timeZone): string
    {
        return $instant->setTimezone($timeZone)
            ->subHours((int) config('devstep.day_rollover_hour'))
            ->toDateString();
    }

    public static function diffDays(string $earlier, string $later): int
    {
        return (int) CarbonImmutable::parse($earlier)->diffInDays(CarbonImmutable::parse($later), false);
    }

    public static function addDays(string $day, int $days): string
    {
        return CarbonImmutable::parse($day)->addDays($days)->toDateString();
    }

    /** Monday of the week containing the learner day (week_start_day = Monday). */
    public static function weekStart(string $day): string
    {
        return CarbonImmutable::parse($day)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
    }
}
