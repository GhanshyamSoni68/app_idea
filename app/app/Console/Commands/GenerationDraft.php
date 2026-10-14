<?php

namespace App\Console\Commands;

use App\Modules\Generation\GenerationException;
use App\Modules\Generation\GenerationService;
use Illuminate\Console\Command;

/**
 * Operator tool: plan a path for a topic and draft its missions, then print what was kept and why anything was not.
 * Nothing is published. Use it to try a new provider or prompt and read the quality results before learners see anything.
 */
final class GenerationDraft extends Command
{
    protected $signature = 'generation:draft {topic : What to learn, in plain words}
        {--level=some experience : beginner | some experience | experienced}
        {--missions=1 : How many missions to draft (0 = outline only, "all" = every topic)}
        {--enable : Run even when GENERATION_ENABLED is off}
        {--json : Print the stored content as JSON}';

    protected $description = 'Plan a learning path and draft its missions with the content engine (nothing is published)';

    public function handle(GenerationService $service): int
    {
        if ($this->option('enable')) {
            config(['generation.enabled' => true]);
        }
        try {
            $planned = $service->planRoadmap(null, (string) $this->argument('topic'), ['level' => (string) $this->option('level')]);
        } catch (GenerationException $e) {
            $this->error("{$e->code_}: {$e->getMessage()}");

            return self::FAILURE;
        }
        $this->info("Plan {$planned['run_id']}: {$planned['plan']['title']}");
        foreach ($planned['plan']['topics'] as $i => $t) {
            $this->line(sprintf('  %d. %-28s %s', $i + 1, $t['key'], $t['title']));
        }

        $want = $this->option('missions') === 'all' ? count($planned['plan']['topics']) : (int) $this->option('missions');
        $kept = 0;
        foreach (array_slice($planned['plan']['topics'], 0, $want) as $t) {
            $this->newLine();
            $this->line("Drafting <comment>{$t['key']}</comment> …");
            try {
                $r = $service->draftMission(null, $planned['run_id'], $planned, $t['key'], ['level' => (string) $this->option('level')]);
            } catch (GenerationException $e) {
                $this->error("  {$e->code_}: {$e->getMessage()}");

                continue;
            }
            if ($r['status'] === 'accepted') {
                $kept++;
                $this->info("  kept after {$r['rounds']} repair round(s); verifier ".($r['independent'] ? 'independent' : 'SAME MODEL').". artifact {$r['artifact_id']}");
                foreach ($r['findings'] as $f) {
                    $this->line("    warning {$f['code']} {$f['path']}: {$f['message']}");
                }
                if ($this->option('json')) {
                    $this->line(json_encode($r['mission'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
            } else {
                $this->warn("  not kept after {$r['rounds']} repair round(s):");
                foreach (array_slice(array_filter($r['findings'], fn ($f) => $f['severity'] === 'error'), 0, 8) as $f) {
                    $this->line("    {$f['code']} {$f['path']}: {$f['message']}");
                }
            }
        }
        $this->newLine();
        $this->line("Kept {$kept} of {$want} mission(s). Nothing was published; see `php artisan generation:runs`.");

        return self::SUCCESS;
    }
}
