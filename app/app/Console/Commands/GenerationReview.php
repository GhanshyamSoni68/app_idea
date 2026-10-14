<?php

namespace App\Console\Commands;

use App\Modules\Generation\GeneratedCatalogue;
use App\Support\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A person reviews AI-made content (docs/16 §7). Approving lifts the evidence cap for attempts made after it; rejecting
 * retires the mission so no new session uses it. The reviewer is a named person, not the author, and must have tried the
 * exercise: the same bar as authored content (docs/06 §7).
 */
final class GenerationReview extends Command
{
    protected $signature = 'generation:review {artifact? : An artifact id, or its first characters}
        {--list : List published, unreviewed missions}
        {--approve : Record a human review}
        {--reject : Retire the mission}
        {--reviewer= : Who reviewed it (a person)}
        {--tried-exercise : The reviewer worked every question themselves}
        {--notes= : Why (shown in the log only)}';

    protected $description = 'List, approve or reject AI-made missions that are in front of learners';

    public function handle(GeneratedCatalogue $catalogue): int
    {
        if ($this->option('list') || ! $this->argument('artifact')) {
            $rows = DB::select("SELECT a.id, a.content_key, a.content->'front'->>'title' AS title, a.quality->>'tier' AS tier, a.status, a.created_at
                                FROM generated_artifacts a WHERE a.kind = 'mission' AND a.published_release_id IS NOT NULL AND a.status = 'quarantined' ORDER BY a.created_at DESC LIMIT 50");
            $this->table(['Artifact', 'Mission', 'Title', 'Verification', 'Made'], array_map(fn ($r) => [substr($r->id, 0, 8), $r->content_key, mb_substr((string) $r->title, 0, 44), $r->tier, substr($r->created_at, 0, 16)], $rows));

            return self::SUCCESS;
        }

        $matches = DB::select("SELECT * FROM generated_artifacts WHERE kind = 'mission' AND id::text LIKE ?", [$this->argument('artifact').'%']);
        if (count($matches) !== 1) {
            $this->error(count($matches) === 0 ? 'No mission artifact matches that.' : 'That matches more than one artifact; use more characters.');

            return self::FAILURE;
        }
        $a = $matches[0];

        if ($this->option('approve') === $this->option('reject')) {
            $this->error('Choose exactly one of --approve or --reject.');

            return self::FAILURE;
        }
        if ($this->option('reject')) {
            $n = $catalogue->retireMission($a->content_key, (string) ($this->option('notes') ?: 'rejected by a reviewer'));
            DB::update("UPDATE generated_artifacts SET status = 'rejected', review_notes = ? WHERE id = ?", [$this->option('notes'), $a->id]);
            $this->info("Retired {$n} content version(s). No new session will use {$a->content_key}.");

            return self::SUCCESS;
        }

        $reviewer = trim((string) $this->option('reviewer'));
        if ($reviewer === '' || in_array(strtolower($reviewer), ['pending', 'ai', 'ai-generated', 'claude', 'model'], true)) {
            $this->error('--reviewer must name a person. A model cannot review its own kind of work (docs/06 §13).');

            return self::FAILURE;
        }
        if (! $this->option('tried-exercise')) {
            $this->error('A reviewer must have worked the exercise themselves. Pass --tried-exercise once that is true.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($a, $reviewer) {
            DB::update("UPDATE generated_artifacts SET status = 'reviewed', reviewed_by = ?, reviewed_at = ?, review_notes = ? WHERE id = ?", [$reviewer, Clock::now(), $this->option('notes'), $a->id]);
            DB::update("UPDATE content_versions SET reviewed_by = ?, last_reviewed_on = ? WHERE content_key = ? OR content_key LIKE ?", [$reviewer, Clock::now()->toDateString(), $a->content_key, $a->content_key.'.%']);
            // A release counts as reviewed once every mission it carries has been.
            $open = DB::selectOne("SELECT 1 FROM generated_artifacts WHERE published_release_id = ? AND kind = 'mission' AND status <> 'reviewed'", [$a->published_release_id]);
            if (! $open) {
                DB::update("UPDATE content_releases SET review_status = 'reviewed' WHERE id = ? AND origin = 'generated'", [$a->published_release_id]);
            }
        });
        $this->info("Recorded {$reviewer}'s review of {$a->content_key}. Evidence from new attempts is no longer capped for its release once all its missions are reviewed.");

        return self::SUCCESS;
    }
}
