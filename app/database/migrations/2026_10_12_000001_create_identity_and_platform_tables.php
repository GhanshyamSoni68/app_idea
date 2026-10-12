<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// docs/04-data-model.md §3.1, §3.5. Raw SQL so CHECKs, partial unique indexes and
// composite keys match the data model exactly. Enumerations are text + CHECK (§6.1).
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE users (
    id uuid PRIMARY KEY,
    status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'pending_deletion')),
    email text NOT NULL,
    email_normalised text NOT NULL,
    email_verified_at timestamptz NULL,
    password_hash text NULL,
    github_user_id text NULL,
    analytics_subject_id uuid NOT NULL,
    last_seen_at timestamptz NOT NULL DEFAULT now(),
    remember_token varchar(100) NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT users_has_login CHECK (password_hash IS NOT NULL OR github_user_id IS NOT NULL)
);
CREATE UNIQUE INDEX uq_users_email ON users (email_normalised);
CREATE UNIQUE INDEX uq_users_github ON users (github_user_id) WHERE github_user_id IS NOT NULL;
CREATE UNIQUE INDEX uq_users_subject ON users (analytics_subject_id);

CREATE TABLE sessions (
    id varchar(255) PRIMARY KEY,
    user_id uuid NULL REFERENCES users (id) ON DELETE CASCADE,
    ip_address varchar(45) NULL,
    user_agent text NULL,
    payload text NOT NULL,
    last_activity integer NOT NULL
);
CREATE INDEX ix_sessions_user ON sessions (user_id);
CREATE INDEX ix_sessions_last_activity ON sessions (last_activity);

CREATE TABLE invites (
    id uuid PRIMARY KEY,
    code_hash text NOT NULL UNIQUE,
    email_normalised text NOT NULL,
    cohort_id text NOT NULL DEFAULT 'alpha',
    arm text NOT NULL DEFAULT 'devstep' CHECK (arm IN ('devstep', 'checklist')),
    expires_at timestamptz NOT NULL,
    redeemed_by uuid NULL UNIQUE REFERENCES users (id) ON DELETE CASCADE,
    redeemed_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT invites_redeemed_pair CHECK ((redeemed_by IS NULL) = (redeemed_at IS NULL))
);

CREATE TABLE learning_preferences (
    user_id uuid PRIMARY KEY REFERENCES users (id) ON DELETE CASCADE,
    time_zone text NOT NULL,
    available_days smallint NOT NULL DEFAULT 62 CHECK (available_days BETWEEN 0 AND 127),
    preferred_mode text NOT NULL DEFAULT 'practise' CHECK (preferred_mode IN ('small', 'practise', 'build')),
    weekly_session_target smallint NOT NULL DEFAULT 3,
    weekly_lab_target smallint NOT NULL DEFAULT 1,
    stack_tags jsonb NOT NULL DEFAULT '[]'::jsonb,
    diagnostic_status text NOT NULL DEFAULT 'not_offered'
        CHECK (diagnostic_status IN ('not_offered', 'skipped', 'partial', 'completed')),
    revision integer NOT NULL DEFAULT 1,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE goals (
    id uuid PRIMARY KEY,
    user_id uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    outcome_key text NOT NULL,
    note text NULL CHECK (char_length(note) <= 280),
    status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'replaced')),
    replaced_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX uq_goals_one_active ON goals (user_id) WHERE status = 'active';

CREATE TABLE idempotency_keys (
    id uuid PRIMARY KEY,
    user_id uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    scope text NOT NULL CHECK (scope IN ('attempt_submit', 'session_complete', 'artifact_submit',
        'guest_claim', 'export_request', 'account_delete', 'enrolment_migrate')),
    key text NOT NULL CHECK (char_length(key) BETWEEN 8 AND 128),
    request_hash text NOT NULL,
    state text NOT NULL CHECK (state IN ('in_progress', 'completed')),
    response_status smallint NULL,
    response_body jsonb NULL CHECK (pg_column_size(response_body) <= 16384),
    resource_ref text NULL,
    expires_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (user_id, scope, key)
);
CREATE INDEX ix_idempotency_expires ON idempotency_keys (expires_at);

-- Pseudonymous events. No foreign keys, so an analytics problem cannot block learning (PRD §12).
CREATE TABLE analytics_events (
    event_id uuid PRIMARY KEY,
    client_event_id uuid NULL,
    subject_id uuid NOT NULL,
    event_name text NOT NULL CHECK (char_length(event_name) <= 64),
    actor text NOT NULL DEFAULT 'learner' CHECK (actor IN ('learner', 'system', 'operator')),
    source text NOT NULL CHECK (source IN ('client', 'server')),
    occurred_at timestamptz NOT NULL,
    received_at timestamptz NOT NULL DEFAULT now(),
    local_date date NULL,
    device_class text NOT NULL DEFAULT 'unknown' CHECK (device_class IN ('phone', 'tablet', 'desktop', 'unknown')),
    session_ref uuid NULL,
    content_version_id uuid NULL,
    roadmap_version_id uuid NULL,
    topic_key text NULL,
    mode text NULL,
    properties jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (pg_column_size(properties) <= 1024),
    schema_version smallint NOT NULL DEFAULT 1,
    app_version text NULL
);
CREATE UNIQUE INDEX uq_events_client ON analytics_events (subject_id, client_event_id) WHERE client_event_id IS NOT NULL;
CREATE INDEX ix_events_name_time ON analytics_events (event_name, occurred_at);
CREATE INDEX ix_events_subject_time ON analytics_events (subject_id, occurred_at);
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS analytics_events, idempotency_keys, goals, learning_preferences, invites, sessions, users CASCADE');
    }
};
