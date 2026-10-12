<?php

namespace App\Modules\Analytics;

/**
 * The event allow-list (docs/10 §3.4-3.6). An unknown event name or property rejects the whole
 * event. No free strings: every string property is a UUID or a declared enum member and every
 * number a bounded integer, so free text has no route in.
 *
 * Property spec: 'uuid' | 'bool' | ['int', min, max] | ['enum', 'a', 'b', ...]
 */
final class EventRegistry
{
    public const OUTCOMES = ['correct', 'partially_correct', 'incorrect', 'self_met', 'self_not_met'];
    public const ASSISTANCE = ['none', 'hint', 'worked_example', 'solution_revealed'];
    public const MODES = ['small', 'practise', 'build'];
    public const ORIGINS = ['today', 'smaller_mode', 'return_screen', 'roadmap', 'reminder_link'];

    /** @return array<string, array{source: string, props: array<string, mixed>, guest?: array<int, string>}> */
    public static function definitions(): array
    {
        $uuidOrNull = 'uuid';

        return [
            'onboarding_completed' => ['source' => 'server', 'props' => [
                'diagnostic_status' => ['enum', 'completed', 'partial', 'skipped', 'not_offered'],
                'diagnostic_items_answered' => ['int', 0, 100],
                'planned_days_per_week' => ['int', 0, 7],
                'preferred_mode' => ['enum', ...self::MODES],
                'reminder_opted_in' => 'bool',
                'goal_key' => ['enum', 'performance', 'reliability', 'architecture', 'general', 'custom'],
            ]],
            'recommendation_seen' => ['source' => 'client', 'props' => [
                'recommendation_id' => ['enum', 'present'],
                'recommendation_kind' => ['enum', 'resume', 'review', 'mission', 'lab', 'recovery', 'sample', 'none'],
                'offered_smaller' => 'bool',
            ]],
            'session_started' => ['source' => 'server', 'props' => [
                'origin' => ['enum', ...self::ORIGINS],
                'has_recommendation' => 'bool',
                'roadmap_enrolment_id' => $uuidOrNull,
                'topic_id' => $uuidOrNull,
            ]],
            'first_answer_submitted' => ['source' => 'server', 'guest' => ['assessment_item_id', 'seconds_since_session_start'], 'props' => [
                'assessment_item_id' => 'uuid',
                'seconds_since_session_start' => ['int', 0, 86400],
            ]],
            'hint_used' => ['source' => 'server', 'props' => [
                'assessment_item_id' => 'uuid',
                'hint_index' => ['int', 1, 10],
                'hint_kind' => ['enum', 'hint', 'worked_example'],
            ]],
            'answer_revealed' => ['source' => 'server', 'props' => [
                'assessment_item_id' => 'uuid',
                'hints_before' => ['int', 0, 10],
                'after_attempt' => 'bool',
            ]],
            'attempt_evaluated' => ['source' => 'server', 'guest' => ['assessment_item_id', 'outcome', 'assistance', 'hint_count'], 'props' => [
                'attempt_id' => 'uuid',
                'assessment_item_id' => 'uuid',
                'skill_id' => 'uuid',
                'attempt_purpose' => ['enum', 'mission', 'small_variant', 'topic_check', 'review', 'challenge_out', 'guest_claim'],
                'outcome' => ['enum', ...self::OUTCOMES],
                'assistance' => ['enum', ...self::ASSISTANCE],
                'hint_count' => ['int', 0, 10],
                'evidence_level' => ['enum', 'none', 'introduced', 'practised', 'demonstrated', 'retained'],
                'evidence_basis' => ['enum', 'auto_scored', 'self_assessed', 'human_reviewed'],
            ]],
            'session_completed' => ['source' => 'server', 'props' => [
                'items_attempted' => ['int', 0, 100],
                'items_met' => ['int', 0, 100],
                'review_items_served' => ['int', 0, 10],
                'duration_s' => ['int', 0, 604800],
                'completion_reason' => ['enum', 'finished', 'stopped_at_point'],
            ]],
            'session_resumed' => ['source' => 'server', 'props' => [
                'gap_minutes' => ['int', 0, 43200],
                'resume_origin' => ['enum', ...self::ORIGINS],
            ]],
            'review_completed' => ['source' => 'server', 'props' => [
                'skill_id' => 'uuid',
                'assessment_item_id' => 'uuid',
                'interval_step' => ['int', 0, 10],
                'outcome' => ['enum', ...self::OUTCOMES],
                'assistance' => ['enum', ...self::ASSISTANCE],
                'next_interval_days' => ['int', 0, 365],
                'is_delayed_check' => 'bool',
            ]],
            'sample_started' => ['source' => 'client', 'guest' => [], 'props' => []],
            'invitation_redeemed' => ['source' => 'server', 'props' => ['invite_id' => 'uuid']],
            'account_created' => ['source' => 'server', 'props' => [
                'was_guest' => 'bool',
                'origin' => ['enum', 'sample_scenario', 'invitation', 'direct'],
            ]],
            'guest_progress_claimed' => ['source' => 'server', 'props' => [
                'attempts_claimed' => ['int', 0, 100],
                'sample_completed' => 'bool',
                'into_existing_account' => 'bool',
            ]],
            'roadmap_enrolled' => ['source' => 'server', 'props' => [
                'roadmap_enrolment_id' => 'uuid',
                'roadmap_id' => 'uuid',
                'roadmap_version_id' => 'uuid',
                'required_topic_count' => ['int', 1, 500],
            ]],
            'topic_started' => ['source' => 'server', 'props' => [
                'topic_id' => 'uuid',
                'topic_kind' => ['enum', 'required', 'optional'],
                'roadmap_version_id' => 'uuid',
            ]],
            'topic_completed' => ['source' => 'server', 'props' => [
                'topic_id' => 'uuid',
                'topic_kind' => ['enum', 'required', 'optional'],
                'completion_basis' => ['enum', 'rules_met', 'challenge_out', 'migrated_credit'],
                'required_completed' => ['int', 0, 500],
                'required_total' => ['int', 0, 500],
            ]],
            'roadmap_completed' => ['source' => 'server', 'props' => [
                'roadmap_enrolment_id' => 'uuid',
                'roadmap_version_id' => 'uuid',
                'elapsed_days' => ['int', 0, 3650],
            ]],
            'return_screen_seen' => ['source' => 'client', 'props' => [
                'days_inactive' => ['int', 0, 365],
                'offered_small' => 'bool',
            ]],
            'small_mode_chosen' => ['source' => 'client', 'props' => [
                'origin' => ['enum', 'today', 'return_screen', 'in_session'],
            ]],
            'error_shown' => ['source' => 'client', 'props' => [
                'error_code' => ['enum', 'offline', 'save_failed', 'sync_conflict', 'server_error', 'not_found', 'rate_limited', 'unknown'],
                'surface' => ['enum', 'today', 'player', 'roadmap', 'evidence', 'lab', 'settings', 'sample'],
            ]],
        ];
    }

    /**
     * Validate an event payload. Returns the sanitised properties, or null to reject the whole event.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function validate(string $name, string $source, array $properties, bool $guest = false): ?array
    {
        $defs = self::definitions();
        if (! isset($defs[$name]) || $defs[$name]['source'] !== $source && ! ($source === 'client' && $guest && isset($defs[$name]['guest']))) {
            return null;
        }
        $def = $defs[$name];
        $allowed = $guest && isset($def['guest']) ? $def['guest'] : array_keys($def['props']);

        if ($guest && ! isset($def['guest'])) {
            return null;
        }

        foreach ($properties as $key => $value) {
            if (! in_array($key, $allowed, true) || ! isset($def['props'][$key]) || ! self::valid($def['props'][$key], $value)) {
                return null;
            }
        }

        return $properties;
    }

    private static function valid(mixed $spec, mixed $value): bool
    {
        if ($spec === 'bool') {
            return is_bool($value);
        }
        if ($spec === 'uuid') {
            return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
        }
        if (is_array($spec) && $spec[0] === 'int') {
            return is_int($value) && $value >= $spec[1] && $value <= $spec[2];
        }
        if (is_array($spec) && $spec[0] === 'enum') {
            return is_string($value) && in_array($value, array_slice($spec, 1), true);
        }

        return false;
    }
}
