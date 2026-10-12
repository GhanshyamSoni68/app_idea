<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// docs/04-data-model.md §3.2 and §4. Published content is insert-only: only retirement fields change.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE content_releases (
    id uuid PRIMARY KEY,
    release_key text NOT NULL UNIQUE,
    source_ref text NULL,
    manifest_hash text NOT NULL,
    published_by text NOT NULL,
    published_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE skills (
    id uuid PRIMARY KEY,
    skill_key text NOT NULL UNIQUE,
    title text NOT NULL,
    is_secondary boolean NOT NULL DEFAULT false,
    status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'retired'))
);

CREATE TABLE skill_prerequisites (
    skill_id uuid NOT NULL REFERENCES skills (id),
    prerequisite_skill_id uuid NOT NULL REFERENCES skills (id),
    PRIMARY KEY (skill_id, prerequisite_skill_id),
    CHECK (skill_id <> prerequisite_skill_id)
);

CREATE TABLE roadmaps (
    id uuid PRIMARY KEY,
    slug text NOT NULL UNIQUE,
    retired_at timestamptz NULL
);

CREATE TABLE roadmap_versions (
    id uuid PRIMARY KEY,
    roadmap_id uuid NOT NULL REFERENCES roadmaps (id),
    version integer NOT NULL,
    status text NOT NULL DEFAULT 'published' CHECK (status IN ('published', 'retired')),
    release_id uuid NOT NULL REFERENCES content_releases (id),
    previous_version_id uuid NULL REFERENCES roadmap_versions (id),
    title text NOT NULL,
    outcome text NOT NULL,
    estimated_effort_hours integer NOT NULL,
    estimated_effort_text text NOT NULL,
    coverage_note text NOT NULL,
    required_topic_count integer NOT NULL CHECK (required_topic_count > 0),
    changelog jsonb NOT NULL DEFAULT '{}'::jsonb,
    published_at timestamptz NOT NULL DEFAULT now(),
    retired_at timestamptz NULL,
    UNIQUE (roadmap_id, version),
    UNIQUE (id, roadmap_id)
);

CREATE TABLE roadmap_modules (
    id uuid PRIMARY KEY,
    roadmap_version_id uuid NOT NULL REFERENCES roadmap_versions (id),
    module_key text NOT NULL,
    position integer NOT NULL,
    title text NOT NULL,
    UNIQUE (roadmap_version_id, module_key),
    UNIQUE (id, roadmap_version_id)
);

CREATE TABLE roadmap_topics (
    id uuid PRIMARY KEY,
    roadmap_version_id uuid NOT NULL,
    module_id uuid NOT NULL,
    topic_key text NOT NULL,
    title text NOT NULL,
    position integer NOT NULL,
    kind text NOT NULL CHECK (kind IN ('required', 'optional')),
    objective_key text NOT NULL,
    objective_major smallint NOT NULL,
    estimated_minutes integer NOT NULL,
    replaces_topic_keys jsonb NOT NULL DEFAULT '[]'::jsonb,
    UNIQUE (roadmap_version_id, topic_key),
    UNIQUE (id, roadmap_version_id),
    FOREIGN KEY (module_id, roadmap_version_id) REFERENCES roadmap_modules (id, roadmap_version_id)
);

CREATE TABLE topic_dependencies (
    roadmap_version_id uuid NOT NULL,
    topic_id uuid NOT NULL,
    depends_on_topic_id uuid NOT NULL,
    strict boolean NOT NULL DEFAULT false,
    PRIMARY KEY (topic_id, depends_on_topic_id),
    CHECK (topic_id <> depends_on_topic_id),
    FOREIGN KEY (topic_id, roadmap_version_id) REFERENCES roadmap_topics (id, roadmap_version_id),
    FOREIGN KEY (depends_on_topic_id, roadmap_version_id) REFERENCES roadmap_topics (id, roadmap_version_id)
);

CREATE TABLE topic_activities (
    id uuid PRIMARY KEY,
    roadmap_version_id uuid NOT NULL,
    topic_id uuid NOT NULL,
    position integer NOT NULL,
    ref_kind text NOT NULL CHECK (ref_kind IN ('mission', 'lab', 'item_group')),
    ref_key text NOT NULL,
    ref_major smallint NOT NULL,
    role text NOT NULL CHECK (role IN ('core', 'enrichment', 'topic_check', 'challenge')),
    is_required boolean NOT NULL DEFAULT true,
    UNIQUE (topic_id, position),
    FOREIGN KEY (topic_id, roadmap_version_id) REFERENCES roadmap_topics (id, roadmap_version_id)
);

CREATE TABLE topic_completion_rules (
    id uuid PRIMARY KEY,
    roadmap_version_id uuid NOT NULL,
    topic_id uuid NOT NULL,
    path text NOT NULL CHECK (path IN ('standard', 'challenge_out')),
    objective_key text NOT NULL,
    objective_major smallint NOT NULL,
    requirements jsonb NOT NULL,
    UNIQUE (topic_id, path),
    FOREIGN KEY (topic_id, roadmap_version_id) REFERENCES roadmap_topics (id, roadmap_version_id)
);

CREATE TABLE content_versions (
    id uuid PRIMARY KEY,
    content_kind text NOT NULL CHECK (content_kind IN ('mission', 'assessment_item', 'lab')),
    content_key text NOT NULL,
    version_major smallint NOT NULL,
    version_minor smallint NOT NULL,
    version_patch smallint NOT NULL,
    defect_fix boolean NOT NULL DEFAULT false,
    status text NOT NULL DEFAULT 'published' CHECK (status IN ('published', 'retired')),
    body jsonb NOT NULL,
    content_hash text NOT NULL,
    source_urls jsonb NOT NULL DEFAULT '[]'::jsonb,
    stack_scope jsonb NOT NULL DEFAULT '{}'::jsonb,
    reviewed_by text NULL,
    last_reviewed_on date NULL,
    release_id uuid NOT NULL REFERENCES content_releases (id),
    published_at timestamptz NOT NULL DEFAULT now(),
    retired_at timestamptz NULL,
    retire_reason text NULL,
    UNIQUE (content_kind, content_key, version_major, version_minor, version_patch),
    CONSTRAINT content_retired_pair CHECK ((status = 'retired') = (retired_at IS NOT NULL))
);
CREATE INDEX ix_content_latest ON content_versions
    (content_kind, content_key, version_major, version_minor DESC, version_patch DESC) WHERE status = 'published';

CREATE TABLE missions (
    content_version_id uuid PRIMARY KEY REFERENCES content_versions (id),
    mode text NOT NULL CHECK (mode IN ('small', 'practise')),
    small_equivalent_key text NULL,
    primary_skill_id uuid NOT NULL REFERENCES skills (id),
    objective_key text NOT NULL,
    objective_major smallint NOT NULL,
    estimated_minutes integer NOT NULL
);

CREATE TABLE assessment_items (
    content_version_id uuid PRIMARY KEY REFERENCES content_versions (id),
    mission_key text NULL,
    skill_id uuid NOT NULL REFERENCES skills (id),
    objective_key text NOT NULL,
    objective_major smallint NOT NULL,
    alternate_group_key text NULL,
    role text NOT NULL CHECK (role IN ('primary', 'topic_check', 'alternate', 'diagnostic', 'transfer', 'delayed_check')),
    check_eligible boolean NOT NULL DEFAULT false,
    retention_reserved boolean NOT NULL DEFAULT false,
    scenario_key text NOT NULL,
    response_kind text NOT NULL,
    scoring text NOT NULL CHECK (scoring IN ('auto', 'self_check', 'human')),
    CONSTRAINT item_not_both_flags CHECK (NOT (check_eligible AND retention_reserved))
);
CREATE INDEX ix_items_group ON assessment_items (alternate_group_key);
CREATE INDEX ix_items_skill ON assessment_items (skill_id);
CREATE INDEX ix_items_mission ON assessment_items (mission_key);

CREATE TABLE labs (
    content_version_id uuid PRIMARY KEY REFERENCES content_versions (id),
    primary_skill_id uuid NOT NULL REFERENCES skills (id),
    kit_ref text NULL,
    kit_sha256 text NULL,
    has_no_setup_fallback boolean NOT NULL DEFAULT false
);

-- Published content is insert-only: only retirement fields may change (docs/04 §4.1).
CREATE OR REPLACE FUNCTION content_versions_immutable() RETURNS trigger AS $$
BEGIN
    IF NEW.id <> OLD.id OR NEW.content_kind <> OLD.content_kind OR NEW.content_key <> OLD.content_key
       OR NEW.version_major <> OLD.version_major OR NEW.version_minor <> OLD.version_minor
       OR NEW.version_patch <> OLD.version_patch OR NEW.body <> OLD.body OR NEW.content_hash <> OLD.content_hash THEN
        RAISE EXCEPTION 'published content is immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_content_versions_immutable BEFORE UPDATE ON content_versions
    FOR EACH ROW EXECUTE FUNCTION content_versions_immutable();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_content_versions_immutable ON content_versions');
        DB::unprepared('DROP FUNCTION IF EXISTS content_versions_immutable()');
        DB::unprepared('DROP TABLE IF EXISTS labs, assessment_items, missions, content_versions, topic_completion_rules, topic_activities, topic_dependencies, roadmap_topics, roadmap_modules, roadmap_versions, roadmaps, skill_prerequisites, skills, content_releases CASCADE');
    }
};
