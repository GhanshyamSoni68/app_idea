<?php

namespace App\Console\Commands;

use App\Modules\Identity\DeletionService;
use Illuminate\Console\Command;

class AccountsReplayLedger extends Command
{
    protected $signature = 'accounts:replay-ledger';

    protected $description = 'After restoring a backup, re-apply every purge recorded in the external deletion ledger';

    public function handle(DeletionService $deletion): int
    {
        $this->info('Re-applied '.$deletion->replayLedger().' purge(s) from the ledger.');

        return 0;
    }
}
