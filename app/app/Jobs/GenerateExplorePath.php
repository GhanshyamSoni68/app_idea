<?php

namespace App\Jobs;

use App\Modules\Generation\ExploreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/** Makes a learner's path: plan, first mission, publish (docs/16 §8). One try: a failure is recorded and shown calmly, never retried blindly. */
class GenerateExplorePath implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public string $runId) {}

    public function handle(ExploreService $explore): void
    {
        $explore->run($this->runId);
    }

    public function failed(\Throwable $e): void
    {
        DB::update("UPDATE generation_runs SET status = 'failed', reason_code = 'unexpected', finished_at = now() WHERE id = ? AND status IN ('queued', 'running')", [$this->runId]);
    }
}
