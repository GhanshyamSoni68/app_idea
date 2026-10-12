<?php

namespace App\Console\Commands;

use App\Modules\Catalogue\ContentPublisher;
use Illuminate\Console\Command;

class ContentPublish extends Command
{
    protected $signature = 'content:publish {--path= : Content directory} {--dev : Allow unreviewed/partial content (never in production)} {--by=founder : Publisher handle} {--ref= : Source revision}';

    protected $description = 'Validate and publish content as immutable versions (deploy-time; there is no admin publish endpoint)';

    public function handle(): int
    {
        $result = ContentValidate::runValidation($this, (string) ($this->option('path') ?: config('devstep.content_path')), (bool) $this->option('dev'));
        if ($result['code'] !== 0) {
            $this->error('Publishing refused: fix the errors above.');

            return $result['code'];
        }
        try {
            $stats = (new ContentPublisher)->publish($result['content'], (string) $this->option('by'), $this->option('ref') ?: null);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return 1;
        }
        $this->info(sprintf('Published release %s: %d missions, %d items, %d roadmap versions (%d drafts skipped).',
            $stats['release_id'], $stats['missions'], $stats['items'], $stats['roadmap_versions'], $stats['skipped']));

        return 0;
    }
}
