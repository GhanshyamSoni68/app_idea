<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// docs/04 §3.1 (auth_tokens) and §3.5 (export_requests, deletion_requests).
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE auth_tokens (
    id uuid PRIMARY KEY,
    user_id uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    purpose text NOT NULL CHECK (purpose IN ('email_verification', 'password_reset', 'email_change')),
    token_hash text NOT NULL UNIQUE,
    expires_at timestamptz NOT NULL,
    used_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ix_auth_tokens_user ON auth_tokens (user_id, purpose);

CREATE TABLE export_requests (
    id uuid PRIMARY KEY,
    user_id uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    status text NOT NULL CHECK (status IN ('queued', 'running', 'ready', 'expired', 'failed')),
    format_version smallint NOT NULL DEFAULT 1,
    file bytea NULL,
    byte_size integer NULL,
    sha256 text NULL,
    expires_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    completed_at timestamptz NULL,
    CONSTRAINT export_ready_has_expiry CHECK (status <> 'ready' OR (expires_at IS NOT NULL AND file IS NOT NULL))
);
CREATE UNIQUE INDEX uq_export_inflight ON export_requests (user_id) WHERE status IN ('queued', 'running');
CREATE INDEX ix_export_expiry ON export_requests (expires_at) WHERE status = 'ready';

-- No FK on user_ref: the row outlives the account as an anonymous exclusion record (docs/04 §8).
CREATE TABLE deletion_requests (
    id uuid PRIMARY KEY,
    user_ref uuid NULL,
    origin text NOT NULL CHECK (origin IN ('learner_request', 'operator')),
    status text NOT NULL CHECK (status IN ('pending', 'cancelled', 'processing', 'completed')),
    requested_at timestamptz NOT NULL,
    effective_at timestamptz NOT NULL,
    cancelled_at timestamptz NULL,
    completed_at timestamptz NULL,
    pilot_cohort_id text NULL,
    pilot_arm text NULL,
    steps jsonb NOT NULL DEFAULT '{}'::jsonb
);
CREATE UNIQUE INDEX uq_deletion_open ON deletion_requests (user_ref) WHERE status IN ('pending', 'processing');
CREATE INDEX ix_deletion_due ON deletion_requests (effective_at) WHERE status = 'pending';
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS deletion_requests, export_requests, auth_tokens CASCADE');
    }
};
