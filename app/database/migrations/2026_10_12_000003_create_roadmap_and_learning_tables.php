<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// docs/04-data-model.md §3.3, §3.4. Learner rows carry user_id; composite FKs (parent_id, user_id)
// make a cross-learner reference impossible inside the database (PRD §11, §12).
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE roadmap_enrolments (
    id uuid PRIMARY KEY,
    user_id uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    roadmap_id uuid NOT NULL,
    roadmap_version_id uuid NOT NULL,
    status text NOT NULL CHECK (status IN ('active', 'paused', 'ended', 'migrated')),
    enrolled_at timestamptz NOT NULL,
    paused_at timestamptz NULL,
    ended_at timestamptz NULL,
    completed_at timestamptz NULL,
    completion_summary jsonb NULL,
    migrated_from_enrolment_id uuid NULL UNIQUE REFERENCES roadmap_enrolments (id),
    migration_summary jsonb NULL,
    declined_version_id uuid NULL REFERENCES roadmap_versions (id),
    declined_at timestamptz NULL,
    reviews_on_exit text NULL CHECK (reviews_on_exit IN ('continue', 'pause')),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (id, user_id),
    UNIQUE (id, user_id, roadmap_version_id),
    FOREIGN KEY (roadmap_version_id, roadmap_id) REFERENCES roadmap_versions (id, roadmap_id),
    CONSTRAINT enrolment_paused_pair CHECK ((status = 'paused') = (paused_at IS NOT NULL))
);
CREATE UNIQUE INDEX uq_enrolments_current ON roadmap_enrolments (user_id) WHERE status IN ('active', 'paused');
CREATE INDEX ix_enrolments_user ON roadmap_enrolments (user_id, enrolled_at);

CREATE TABLE learning_sessions (
    id uuid PRIMARY KEY,
    user_id uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    mode text NOT NULL CHECK (mode IN ('small', 'practise', 'build')),
    purpose text NOT NULL CHECK (purpose IN ('roadmap', 'recovery', 'diagnostic', 'sample', 'challenge', 'maintenance', 'review')),
    status text NOT NULL CHECK (status IN ('open', 'suspended', 'completed', 'abandoned')),
    enrolment_id uuid NULL,
    topic_id uuid NULL REFERENCES roadmap_topics (id),
    content_version_id uuid NULL REFERENCES content_versions (id),
    plan jsonb NOT NULL,
    recommendation_id text NULL,
    local_date date NOT NULL,
    started_at timestamptz NOT NULL,
    last_activity_at timestamptz NOT NULL,
    completed_at timestamptz NULL,
    UNIQUE (id, user_id),
    FOREIGN KEY (enrolment_id, user_id) REFERENCES roadmap_enrolments (id, user_id),
    CONSTRAINT session_completed_pair CHECK ((status = 'completed') = (completed_at IS NOT NULL))
);
CREATE UNIQUE INDEX uq_sessions_one_open ON learning_sessions (user_id) WHERE status = 'open';
CREATE INDEX ix_sessions_user_activity ON learning_sessions (user_id, last_activity_at DESC);
CREATE INDEX ix_sessions_user_done_day ON learning_sessions (user_id, local_date) WHERE status = 'completed';

CREATE TABLE session_drafts (
    session_id uuid PRIMARY KEY,
    user_id uuid NOT NULL,
    revision integer NOT NULL CHECK (revision >= 1),
    step_key text NOT NULL,
    payload jsonb NOT NULL CHECK (pg_column_size(payload) <= 65536),
    last_save_id uuid NOT NULL,
    device_label text NULL CHECK (char_length(device_label) <= 60),
    updated_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (session_id, user_id) REFERENCES learning_sessions (id, user_id) ON DELETE CASCADE
);

CREATE TABLE session_assistance (
    id uuid PRIMARY KEY,
    user_id uuid NOT NULL,
    session_id uuid NOT NULL,
    step_key text NOT NULL,
    content_version_id uuid NOT NULL REFERENCES content_versions (id),
    kind text NOT NULL CHECK (kind IN ('hint', 'worked_example', 'solution_revealed')),
    hint_index smallint NOT NULL CHECK (hint_index >= 0),
    used_at timestamptz NOT NULL,
    UNIQUE (session_id, step_key, kind, hint_index),
    FOREIGN KEY (session_id, user_id) REFERENCES learning_sessions (id, user_id) ON DELETE CASCADE
);

CREATE TABLE attempts (
    id uuid PRIMARY KEY,
    user_id uuid NOT NULL,
    session_id uuid NOT NULL,
    content_version_id uuid NOT NULL REFERENCES content_versions (id),
    step_key text NOT NULL,
    attempt_no smallint NOT NULL,
    client_attempt_id uuid NOT NULL,
    purpose text NOT NULL CHECK (purpose IN ('in_mission', 'small_variant', 'retrieval', 'topic_check', 'challenge', 'lab', 'diagnostic', 'transfer')),
    origin text NOT NULL CHECK (origin IN ('session', 'guest_claim')),
    response jsonb NOT NULL CHECK (pg_column_size(response) <= 65536),
    outcome text NULL CHECK (outcome IN ('correct', 'partially_correct', 'incorrect', 'self_met', 'self_not_met')),
    score numeric NULL,
    evaluated_at timestamptz NULL,
    evidence_basis text NOT NULL CHECK (evidence_basis IN ('auto_scored', 'self_assessed', 'human_reviewed')),
    assistance text NOT NULL CHECK (assistance IN ('none', 'hint', 'worked_example', 'solution_revealed')),
    hint_count smallint NOT NULL DEFAULT 0,
    submitted_at timestamptz NOT NULL,
    feedback_viewed_at timestamptz NULL,
    UNIQUE (id, user_id),
    UNIQUE (session_id, step_key, attempt_no),
    UNIQUE (user_id, client_attempt_id),
    FOREIGN KEY (session_id, user_id) REFERENCES learning_sessions (id, user_id) ON DELETE CASCADE,
    CONSTRAINT attempt_eval_pair CHECK ((outcome IS NULL) = (evaluated_at IS NULL)),
    CONSTRAINT attempt_self_basis CHECK (outcome NOT IN ('self_met', 'self_not_met') OR evidence_basis = 'self_assessed'),
    CONSTRAINT attempt_assist_hints CHECK ((assistance = 'none' AND hint_count = 0) OR (assistance <> 'none')),
    CONSTRAINT attempt_hint_assist CHECK (assistance <> 'hint' OR hint_count >= 1)
);
CREATE INDEX ix_attempts_user_time ON attempts (user_id, submitted_at);
CREATE INDEX ix_attempts_version ON attempts (content_version_id);

CREATE TABLE skill_evidence (
    id uuid PRIMARY KEY,
    user_id uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    skill_id uuid NOT NULL REFERENCES skills (id),
    level text NOT NULL CHECK (level IN ('introduced', 'practised', 'demonstrated', 'retained')),
    basis text NOT NULL CHECK (basis IN ('auto_scored', 'self_assessed', 'learner_submitted', 'human_reviewed')),
    assistance text NOT NULL CHECK (assistance IN ('none', 'hint', 'worked_example', 'solution_revealed')),
    source text NOT NULL CHECK (source IN ('attempt', 'artifact', 'reading', 'human_review', 'guest_claim')),
    attempt_id uuid NULL UNIQUE,
    artifact_id uuid NULL,
    content_version_id uuid NULL REFERENCES content_versions (id),
    objective_key text NOT NULL,
    objective_major smallint NOT NULL,
    limitations jsonb NOT NULL DEFAULT '[]'::jsonb,
    observed_at timestamptz NOT NULL,
    voided_at timestamptz NULL,
    void_reason text NULL CHECK (void_reason IN ('artifact_deleted', 'duplicate')),
    FOREIGN KEY (attempt_id, user_id) REFERENCES attempts (id, user_id) ON DELETE CASCADE,
    CONSTRAINT evidence_reveal_never_demonstrates CHECK (NOT (level IN ('demonstrated', 'retained') AND assistance = 'solution_revealed')),
    CONSTRAINT evidence_reading_only_introduced CHECK (level = 'introduced' OR attempt_id IS NOT NULL OR artifact_id IS NOT NULL OR voided_at IS NOT NULL),
    CONSTRAINT evidence_artifact_not_auto CHECK (artifact_id IS NULL OR basis <> 'auto_scored'),
    CONSTRAINT evidence_guest_claim_cap CHECK (source <> 'guest_claim' OR level IN ('introduced', 'practised')),
    CONSTRAINT evidence_void_pair CHECK ((voided_at IS NULL) = (void_reason IS NULL))
);
CREATE INDEX ix_evidence_user_skill_time ON skill_evidence (user_id, skill_id, observed_at);

-- One review track per objective version (docs/04 §3.4). Engine bookkeeping columns (RV-2) are added
-- here: check_pending_topic_id, consecutive_* counters, used_items history, skipped_on.
CREATE TABLE review_schedule (
    id uuid PRIMARY KEY,
    user_id uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    skill_id uuid NOT NULL REFERENCES skills (id),
    objective_key text NOT NULL,
    objective_major smallint NOT NULL,
    alternate_group_key text NOT NULL,
    status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended')),
    interval_step smallint NOT NULL DEFAULT 0 CHECK (interval_step BETWEEN 0 AND 10),
    due_on date NOT NULL,
    last_item_version_id uuid NULL REFERENCES content_versions (id),
    last_attempt_id uuid NULL,
    last_outcome text NULL,
    demonstrated_at timestamptz NULL,
    retained_at timestamptz NULL,
    source_enrolment_id uuid NULL,
    check_pending_topic_id uuid NULL REFERENCES roadmap_topics (id),
    consecutive_not_met smallint NOT NULL DEFAULT 0,
    consecutive_check_failures smallint NOT NULL DEFAULT 0,
    consecutive_skips smallint NOT NULL DEFAULT 0,
    skipped_on date NULL,
    used_items jsonb NOT NULL DEFAULT '[]'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (user_id, objective_key, objective_major)
);
CREATE INDEX ix_review_due ON review_schedule (user_id, due_on) WHERE status = 'active';

CREATE TABLE topic_progress (
    id uuid PRIMARY KEY,
    user_id uuid NOT NULL,
    enrolment_id uuid NOT NULL,
    roadmap_version_id uuid NOT NULL,
    topic_id uuid NOT NULL,
    state text NOT NULL DEFAULT 'not_started' CHECK (state IN ('not_started', 'in_progress', 'completed', 'deferred')),
    completion_path text NULL CHECK (completion_path IN ('standard', 'challenge_out', 'carried_over', 'reused_evidence')),
    completion_attempt_id uuid NULL,
    carried_from_progress_id uuid NULL REFERENCES topic_progress (id),
    mission_activity_done_on date NULL,
    mission_activity_done_at timestamptz NULL,
    started_at timestamptz NULL,
    completed_at timestamptz NULL,
    deferred_at timestamptz NULL,
    refresh_suggested_at timestamptz NULL,
    refresh_reason text NULL CHECK (refresh_reason IN ('objective_changed')),
    UNIQUE (id, user_id),
    UNIQUE (enrolment_id, topic_id),
    FOREIGN KEY (enrolment_id, user_id, roadmap_version_id) REFERENCES roadmap_enrolments (id, user_id, roadmap_version_id) ON DELETE CASCADE,
    FOREIGN KEY (topic_id, roadmap_version_id) REFERENCES roadmap_topics (id, roadmap_version_id),
    FOREIGN KEY (completion_attempt_id, user_id) REFERENCES attempts (id, user_id),
    CONSTRAINT progress_completed_pair CHECK ((state = 'completed') = (completed_at IS NOT NULL AND completion_path IS NOT NULL)),
    CONSTRAINT progress_deferred_at CHECK (state <> 'deferred' OR deferred_at IS NOT NULL),
    CONSTRAINT progress_carried_needs_source CHECK (completion_path <> 'carried_over' OR carried_from_progress_id IS NOT NULL),
    CONSTRAINT progress_path_needs_attempt CHECK (completion_path IS NULL OR completion_path = 'carried_over' OR completion_path = 'reused_evidence' OR completion_attempt_id IS NOT NULL)
);

-- A completed topic never reverts (R02, TP-2).
CREATE OR REPLACE FUNCTION topic_progress_completed_terminal() RETURNS trigger AS $$
BEGIN
    IF OLD.state = 'completed' AND NEW.state <> 'completed' THEN
        RAISE EXCEPTION 'a completed topic cannot leave completed';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_topic_progress_terminal BEFORE UPDATE ON topic_progress
    FOR EACH ROW EXECUTE FUNCTION topic_progress_completed_terminal();

-- skill_evidence is append-only; only voiding may change a row (docs/04 §6.2).
CREATE OR REPLACE FUNCTION skill_evidence_append_only() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;  -- cascades from account deletion are allowed
    END IF;
    IF NEW.level <> OLD.level OR NEW.basis <> OLD.basis OR NEW.assistance <> OLD.assistance
       OR NEW.skill_id <> OLD.skill_id OR NEW.observed_at <> OLD.observed_at THEN
        RAISE EXCEPTION 'skill_evidence is append-only';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_skill_evidence_append_only BEFORE UPDATE ON skill_evidence
    FOR EACH ROW EXECUTE FUNCTION skill_evidence_append_only();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_skill_evidence_append_only ON skill_evidence');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_topic_progress_terminal ON topic_progress');
        DB::unprepared('DROP FUNCTION IF EXISTS skill_evidence_append_only()');
        DB::unprepared('DROP FUNCTION IF EXISTS topic_progress_completed_terminal()');
        DB::unprepared('DROP TABLE IF EXISTS topic_progress, review_schedule, skill_evidence, attempts, session_assistance, session_drafts, learning_sessions, roadmap_enrolments CASCADE');
    }
};
