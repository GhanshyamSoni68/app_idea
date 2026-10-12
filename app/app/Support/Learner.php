<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** The signed-in learner plus the time-zone facts every rule needs (schedules use the learner's IANA zone). */
final class Learner
{
    public function __construct(
        public readonly string $id,
        public readonly string $subjectId,
        public readonly string $timeZone,
    ) {}

    public static function of(User $user): self
    {
        $tz = DB::scalar('SELECT time_zone FROM learning_preferences WHERE user_id = ?', [$user->id]) ?: 'UTC';

        return new self((string) $user->id, (string) $user->analytics_subject_id, (string) $tz);
    }

    public function day(?CarbonImmutable $at = null): string
    {
        return LearnerDay::of($at ?? Clock::now(), $this->timeZone);
    }

    public function dayOf(string $instant): string
    {
        return LearnerDay::of(CarbonImmutable::parse($instant), $this->timeZone);
    }
}
