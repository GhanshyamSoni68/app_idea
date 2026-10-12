<?php

namespace App\Console\Commands;

use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ClockSet extends Command
{
    protected $signature = 'clock:set {instant? : RFC 3339 instant, or +1day / +2hours} {--reset : Remove the override}';

    protected $description = 'Control the application clock outside production (docs/12 EP01)';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('The clock cannot be changed in production.');

            return 2;
        }
        $path = Clock::overridePath();
        if ($this->option('reset') || $this->argument('instant') === 'reset') {
            @unlink($path);
            $this->info('Clock override removed.');

            return 0;
        }
        $arg = (string) $this->argument('instant');
        if ($arg === '') {
            $this->error('Give an instant, or use --reset.');

            return 2;
        }
        $instant = str_starts_with($arg, '+')
            ? CarbonImmutable::parse($arg, 'UTC')->setTimestamp(Clock::now()->getTimestamp())->modify($arg)
            : CarbonImmutable::parse($arg, 'UTC');
        file_put_contents($path, $instant->utc()->toIso8601String());
        $this->info('Clock is now '.$instant->utc()->toIso8601String());

        return 0;
    }
}
