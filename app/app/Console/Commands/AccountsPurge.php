<?php

namespace App\Console\Commands;

use App\Modules\Identity\DeletionService;
use Illuminate\Console\Command;

class AccountsPurge extends Command
{
    protected $signature = 'accounts:purge';

    protected $description = 'Delete accounts whose 7-day deletion grace has ended (also removes their analytics events)';

    public function handle(DeletionService $deletion): int
    {
        $this->info('Purged '.$deletion->purgeDue().' account(s).');

        return 0;
    }
}
