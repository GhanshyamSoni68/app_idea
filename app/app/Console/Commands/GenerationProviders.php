<?php

namespace App\Console\Commands;

use App\Modules\Generation\GenerationException;
use App\Modules\Generation\Llm\LlmRequest;
use App\Modules\Generation\ProviderManager;
use App\Modules\Generation\RunTally;
use Illuminate\Console\Command;

/** Shows what the content engine is wired to, and (with --ping) proves each ready provider really answers. */
final class GenerationProviders extends Command
{
    protected $signature = 'generation:providers {--ping : Send one tiny request to every ready provider on a route}';

    protected $description = 'List content-generation providers, their routes and health';

    public function handle(ProviderManager $providers): int
    {
        $this->line('Generation is '.(config('generation.enabled') ? '<info>ON</info>' : '<comment>OFF</comment> (GENERATION_ENABLED=false)'));
        foreach (['plan', 'draft', 'verify'] as $task) {
            $this->line(sprintf('  route %-6s %s', $task, implode(' → ', $providers->route($task)) ?: '(none)'));
        }
        $this->newLine();
        $rows = [];
        foreach ($providers->health() as $name => $h) {
            $rows[] = [$name, $h['driver'], $h['model'] ?: '-', $h['ready'] ? 'ready' : 'not ready', $h['problem'] ?? ($h['circuit_open'] ? 'circuit open' : ''), implode(',', $h['routes'])];
        }
        $this->table(['Provider', 'Driver', 'Model', 'State', 'Note', 'Used for'], $rows);

        $failed = 0;
        if ($this->option('ping')) {
            foreach ($providers->health() as $name => $h) {
                if (! $h['ready'] || $h['routes'] === []) {
                    continue;
                }
                $started = microtime(true);
                try {
                    $p = $providers->provider($name);
                    $r = $p->complete(new LlmRequest('ping', 'Reply with the single word: ok', [['role' => 'user', 'content' => 'ping']], null, 'ping', 0.0, 20));
                    $this->info(sprintf('  %-16s ok in %d ms (%s)', $name, (microtime(true) - $started) * 1000, $r->model));
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error(sprintf('  %-16s FAILED: %s', $name, $e->getMessage()));
                }
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
