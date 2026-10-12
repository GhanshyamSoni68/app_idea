<?php

namespace App\Console\Commands;

use App\Modules\Catalogue\ContentLoader;
use App\Modules\Catalogue\ContentValidator;
use Illuminate\Console\Command;

class ContentValidate extends Command
{
    protected $signature = 'content:validate {--path= : Content directory} {--dev : Review-state and missing-mission findings become warnings (never in production)}';

    protected $description = 'Validate the content directory without publishing (docs/06 §9)';

    public function handle(): int
    {
        return self::runValidation($this, (string) ($this->option('path') ?: config('devstep.content_path')), (bool) $this->option('dev'))['code'];
    }

    /** @return array{code:int, content:array|null} */
    public static function runValidation(Command $cmd, string $path, bool $dev): array
    {
        if ($dev && app()->isProduction()) {
            $cmd->error('--dev is not allowed in production: review gates cannot be bypassed.');

            return ['code' => 2, 'content' => null];
        }
        if (! is_dir($path)) {
            $cmd->error("Content directory not found: {$path}");

            return ['code' => 2, 'content' => null];
        }
        $loader = new ContentLoader;
        $content = $loader->load($path);
        $findings = [];
        foreach ($loader->problems as $p) {
            $findings[] = ['severity' => 'error', 'code' => $p['code'], 'path' => $p['path'], 'message' => $p['message']];
        }
        $findings = array_merge($findings, (new ContentValidator)->validate($content, ['allow_unreviewed' => $dev, 'allow_partial' => $dev]));

        foreach ($findings as $f) {
            $line = sprintf('[%s] %s %s: %s', strtoupper($f['severity']), $f['code'], $f['path'], $f['message']);
            $f['severity'] === 'error' ? $cmd->error($line) : $cmd->warn($line);
        }
        $errors = count(array_filter($findings, fn ($f) => $f['severity'] === 'error'));
        $cmd->info(sprintf('%d missions, %d roadmaps, %d errors, %d warnings.', count($content['missions']), count($content['roadmaps']), $errors, count($findings) - $errors));

        return ['code' => $errors > 0 ? 1 : 0, 'content' => $errors > 0 ? null : $content];
    }
}
