<?php

namespace App\Console\Commands;

use App\Support\Clock;
use App\Support\Ids;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InviteCreate extends Command
{
    protected $signature = 'invite:create {email} {--cohort=alpha} {--arm=devstep} {--days=30}';

    protected $description = 'Create a single-use pilot invite bound to an email (sign-up is invite-only)';

    public function handle(): int
    {
        $code = Str::upper(Str::random(4).'-'.Str::random(4).'-'.Str::random(4));
        DB::insert(
            'INSERT INTO invites (id, code_hash, email_normalised, cohort_id, arm, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [Ids::new(), hash('sha256', $code), Str::lower(trim((string) $this->argument('email'))), $this->option('cohort'), $this->option('arm'),
                Clock::now()->addDays((int) $this->option('days')), Clock::now()],
        );
        $this->line($code);
        $this->comment('Shown once: only a hash is stored. Single use; sign-up must use the invited email.');

        return 0;
    }
}
