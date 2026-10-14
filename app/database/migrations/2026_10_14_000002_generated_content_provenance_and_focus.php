<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// docs/16 §7: generated content is labelled in the catalogue itself, and a learner can hold more than one path with one of them
// in focus. A path made for one learner is visible only to that learner.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE content_releases
    ADD COLUMN origin text NOT NULL DEFAULT 'authored' CHECK (origin IN ('authored', 'generated')),
    ADD COLUMN review_status text NOT NULL DEFAULT 'reviewed' CHECK (review_status IN ('reviewed', 'unreviewed')),
    ADD CONSTRAINT release_authored_is_reviewed CHECK (origin = 'generated' OR review_status = 'reviewed');

ALTER TABLE roadmaps
    ADD COLUMN owner_user_id uuid NULL REFERENCES users (id) ON DELETE SET NULL;
CREATE INDEX ix_roadmaps_owner ON roadmaps (owner_user_id) WHERE owner_user_id IS NOT NULL;

-- One path in focus at a time; any number parked (paused), but never the same roadmap twice.
DROP INDEX uq_enrolments_current;
CREATE UNIQUE INDEX uq_enrolments_active ON roadmap_enrolments (user_id) WHERE status = 'active';
CREATE UNIQUE INDEX uq_enrolments_roadmap_current ON roadmap_enrolments (user_id, roadmap_id) WHERE status IN ('active', 'paused');
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP INDEX IF EXISTS uq_enrolments_roadmap_current;
DROP INDEX IF EXISTS uq_enrolments_active;
CREATE UNIQUE INDEX uq_enrolments_current ON roadmap_enrolments (user_id) WHERE status IN ('active', 'paused');
DROP INDEX IF EXISTS ix_roadmaps_owner;
ALTER TABLE roadmaps DROP COLUMN owner_user_id;
ALTER TABLE content_releases DROP CONSTRAINT release_authored_is_reviewed, DROP COLUMN review_status, DROP COLUMN origin;
SQL);
    }
};
