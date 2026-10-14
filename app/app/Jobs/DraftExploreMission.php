<?php

namespace App\Jobs;

use App\Modules\Generation\ExploreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Drafts one more topic of a published path. Its failure is already logged; the hourly backfill decides about retries. */
class DraftExploreMission implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public string $runId, public string $topicKey) {}

    public function handle(ExploreService $explore): void
    {
        $explore->draftLater($this->runId, $this->topicKey);
    }
}
