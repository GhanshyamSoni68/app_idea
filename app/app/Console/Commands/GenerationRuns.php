<?php

namespace App\Console\Commands;

use App\Modules\Generation\GenerationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Recent runs with their outcome, spend and (with --calls) every model call. The topic text is shown only with --topics. */
final class GenerationRuns extends Command
{
    protected $signature = 'generation:runs {--limit=15} {--calls : Show the model calls of each run} {--topics : Show topic text (it is learner-typed)} {--purge : Drop prompts and replies past the retention window}';

    protected $description = 'Show recent content-generation runs, their cost and how each ended';

    public function handle(): int
    {
        if ($this->option('purge')) {
            $this->info('Dropped '.GenerationLog::purgePayloads().' stored prompt/reply payload(s).');
        }
        $runs = DB::select('SELECT * FROM generation_runs ORDER BY created_at DESC LIMIT ?', [(int) $this->option('limit')]);
        $rows = [];
        foreach ($runs as $r) {
            $rows[] = [substr($r->id, 0, 8), $r->kind, $r->status, $r->reason_code ?? '', $r->repair_rounds, $r->verifier_independent === null ? '-' : ($r->verifier_independent ? 'yes' : 'no'),
                $r->input_tokens + $r->output_tokens, number_format((float) $r->cost_usd, 4), $this->option('topics') ? mb_substr((string) $r->topic, 0, 40) : substr($r->topic_hash, 0, 8)];
        }
        $this->table(['Run', 'Kind', 'Status', 'Reason', 'Repairs', 'Indep.', 'Tokens', 'USD', $this->option('topics') ? 'Topic' : 'Topic hash'], $rows);
        if ($this->option('calls')) {
            foreach ($runs as $r) {
                $this->line("\nRun ".substr($r->id, 0, 8));
                foreach (DB::select('SELECT * FROM generation_calls WHERE run_id = ? ORDER BY created_at', [$r->id]) as $c) {
                    $this->line(sprintf('  %-9s %-16s %-22s try %d %s %5d ms %6d tok $%s %s', $c->task, $c->provider, mb_substr($c->model, 0, 22), $c->attempt, $c->ok ? 'ok ' : 'ERR', $c->latency_ms, $c->input_tokens + $c->output_tokens, number_format((float) $c->cost_usd, 4), $c->error_kind ?? ''));
                }
            }
        }
        $this->line(sprintf("\nSpent in the last 24 hours: $%s of $%s", number_format((new GenerationLog)->spentToday(), 4), number_format((float) config('generation.budget.daily_usd'), 2)));

        return self::SUCCESS;
    }
}
