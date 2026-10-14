<?php

namespace App\Console\Commands;

use App\Modules\Generation\ExploreService;
use Illuminate\Console\Command;

/** Retries topics of published personal paths whose mission could not be made yet (docs/16 §8). */
final class GenerationBackfill extends Command
{
    protected $signature = 'generation:backfill';

    protected $description = 'Queue another try for personal-path topics that have no mission yet (a few tries in all)';

    public function handle(ExploreService $explore): int
    {
        if (! ExploreService::enabled()) {
            return self::SUCCESS;
        }
        $this->info('Queued '.$explore->backfill().' topic(s).');

        return self::SUCCESS;
    }
}
