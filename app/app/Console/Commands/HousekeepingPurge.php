<?php

namespace App\Console\Commands;

use App\Modules\Generation\GenerationLog;
use App\Modules\Identity\ExportService;
use App\Support\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class HousekeepingPurge extends Command
{
    protected $signature = 'housekeeping:purge';

    protected $description = 'Expire export files, delete idempotency keys and auth tokens past their expiry, and drop old model payloads (docs/09 §8, docs/16 §6)';

    public function handle(ExportService $exports): int
    {
        $e = $exports->purgeExpired();
        $k = DB::delete('DELETE FROM idempotency_keys WHERE expires_at <= ?', [Clock::now()]);
        $t = DB::delete('DELETE FROM auth_tokens WHERE expires_at <= ? OR used_at <= ?', [Clock::now()->subDay(), Clock::now()->subDay()]);
        $g = GenerationLog::purgePayloads();
        $this->info("Expired {$e} export(s), deleted {$k} idempotency key(s) and {$t} token(s), dropped {$g} stored model payload(s).");

        return 0;
    }
}
