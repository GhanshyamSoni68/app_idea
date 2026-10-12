<?php

// Parameters registry: docs/05-learning-engine.md §13. Every learning-engine number lives here.
return [
    'day_rollover_hour' => 4,
    'budget' => ['small' => 3, 'practise' => 10, 'build' => 40],
    'duration_tolerance' => 0.3,
    'review_cap' => ['practise' => 2, 'small' => 1, 'build' => 1],
    'daily_review_cap' => 4,
    'review_ladder' => [1, 3, 7, 21],
    'initial_interval_days' => 1,
    'maintenance_interval_days' => 60,
    'heavy_hint_threshold' => 2,
    'heavy_assist_step_down' => 1,
    'challenge_pass_rung' => 2,
    'topic_check_pass_rung' => 2,
    'topic_check_delay_days' => 1,
    'topic_check_max_hints' => 1,
    'refresher_after_not_met' => 2,
    'check_lock_after_failures' => 3,
    'retained_min_delay_days' => 7,
    'prompt_reuse_min_days' => 14,
    'skip_deprioritise_after' => 3,
    'long_absence_days' => 7,
    'resume_recap_after_days' => 7,
    'resume_dismiss_limit' => 2,
    'deferred_resurface_days' => 7,
    'weekly_target_default' => 3,
    'open_response_min_chars' => 40,
    'selfcheck_supporting_ratio' => 0.5,
    'est_review_item_minutes' => 1.5,
    'est_topic_check_minutes' => 2,
    'early_review_extends' => false,

    // Content directory published by `content:publish` (a deploy-time command, no admin endpoint).
    'content_path' => env('DEVSTEP_CONTENT_PATH', base_path('../content')),

    // The one sample mission served to guests before sign-up (docs/03 Flow 1).
    'sample_mission' => 'perf.query-plans',
    'launch_roadmap' => 'crud-to-reliable',

    // Idempotency keys expire after 7 days (docs/04 §5.4).
    'idempotency_ttl_days' => 7,

    // Analytics payload allow-list lives in app/Modules/Analytics/EventRegistry.php.
    'guest_bundle_max_attempts' => 20,
];
