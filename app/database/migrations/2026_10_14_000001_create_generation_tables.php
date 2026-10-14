<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Content engine bookkeeping (docs/16 §6). Runs and calls are the audit trail; artifacts are the generated content itself.
// A learner's deletion keeps the cost and quality records but removes the topic text (see DeletionService).
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE generation_runs (
    id uuid PRIMARY KEY,
    user_id uuid NULL REFERENCES users (id) ON DELETE SET NULL,
    parent_run_id uuid NULL REFERENCES generation_runs (id) ON DELETE SET NULL,
    kind text NOT NULL CHECK (kind IN ('roadmap', 'mission')),
    status text NOT NULL CHECK (status IN ('queued', 'running', 'accepted', 'rejected', 'failed', 'cancelled')),
    stage text NOT NULL DEFAULT 'queued',
    topic text NULL,
    topic_hash text NOT NULL,
    params jsonb NOT NULL DEFAULT '{}'::jsonb,
    prompt_version text NOT NULL,
    reason_code text NULL,
    reason_detail jsonb NULL,
    repair_rounds smallint NOT NULL DEFAULT 0,
    verifier_independent boolean NULL,
    input_tokens integer NOT NULL DEFAULT 0,
    output_tokens integer NOT NULL DEFAULT 0,
    cost_usd numeric(10, 4) NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now(),
    started_at timestamptz NULL,
    finished_at timestamptz NULL
);
CREATE INDEX ix_generation_runs_user ON generation_runs (user_id, created_at DESC);
CREATE INDEX ix_generation_runs_parent ON generation_runs (parent_run_id);
CREATE INDEX ix_generation_runs_status ON generation_runs (status, created_at);

CREATE TABLE generation_calls (
    id uuid PRIMARY KEY,
    run_id uuid NULL REFERENCES generation_runs (id) ON DELETE CASCADE,
    task text NOT NULL,
    provider text NOT NULL,
    model text NOT NULL,
    attempt smallint NOT NULL DEFAULT 1,
    ok boolean NOT NULL,
    error_kind text NULL,
    error_message text NULL,
    latency_ms integer NOT NULL DEFAULT 0,
    input_tokens integer NOT NULL DEFAULT 0,
    output_tokens integer NOT NULL DEFAULT 0,
    cost_usd numeric(10, 4) NOT NULL DEFAULT 0,
    finish_reason text NULL,
    prompt_version text NULL,
    request_hash text NULL,
    payload jsonb NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ix_generation_calls_run ON generation_calls (run_id, created_at);
CREATE INDEX ix_generation_calls_time ON generation_calls (created_at);
CREATE INDEX ix_generation_calls_provider ON generation_calls (provider, created_at);

-- The generated content itself, in the same shape ContentLoader produces, plus the evidence for why it was kept.
CREATE TABLE generated_artifacts (
    id uuid PRIMARY KEY,
    run_id uuid NOT NULL REFERENCES generation_runs (id) ON DELETE CASCADE,
    owner_user_id uuid NULL REFERENCES users (id) ON DELETE SET NULL,
    kind text NOT NULL CHECK (kind IN ('roadmap_plan', 'mission')),
    content_key text NOT NULL,
    content jsonb NOT NULL,
    content_hash text NOT NULL,
    quality jsonb NOT NULL DEFAULT '{}'::jsonb,
    status text NOT NULL DEFAULT 'quarantined' CHECK (status IN ('quarantined', 'reviewed', 'rejected', 'retired')),
    reviewed_by text NULL,
    reviewed_at timestamptz NULL,
    review_notes text NULL,
    published_release_id uuid NULL REFERENCES content_releases (id),
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT artifact_review_pair CHECK ((status = 'reviewed') = (reviewed_by IS NOT NULL))
);
CREATE INDEX ix_generated_artifacts_run ON generated_artifacts (run_id);
CREATE INDEX ix_generated_artifacts_key ON generated_artifacts (kind, content_key);
CREATE INDEX ix_generated_artifacts_review ON generated_artifacts (status, created_at);
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS generated_artifacts, generation_calls, generation_runs CASCADE');
    }
};
