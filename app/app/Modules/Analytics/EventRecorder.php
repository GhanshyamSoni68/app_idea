<?php

namespace App\Modules\Analytics;

use App\Support\Clock;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * Writes pseudonymous events. Callers invoke this AFTER the learning transaction commits, and a
 * failure here is logged and swallowed: an analytics outage never blocks learning (PRD §12).
 */
final class EventRecorder
{
    private const NAMESPACE = '6f3c1c52-9f5d-4a0e-9d54-0a3f7bd9a1c2';

    /**
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $context  session_ref, content_version_id, roadmap_version_id, topic_key, mode, local_date, device_class
     * @param  string|null  $dedupeKey  Entity ID that makes the event exactly-once (deterministic event_id)
     */
    public static function server(string $name, string $subjectId, array $properties = [], array $context = [], ?string $dedupeKey = null, string $actor = 'learner'): void
    {
        try {
            $clean = EventRegistry::validate($name, 'server', $properties);
            if ($clean === null) {
                Log::warning('analytics event rejected', ['event' => $name]);

                return;
            }
            self::insert($name, $subjectId, 'server', $clean, $context, null, $dedupeKey, $actor);
        } catch (\Throwable $e) {
            Log::warning('analytics write failed', ['event' => $name, 'error' => $e::class]);
        }
    }

    /**
     * Client batch events (POST /v1/events). Returns true when the event was accepted.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function client(string $name, string $subjectId, array $properties, ?string $clientEventId, bool $guest, array $context = []): bool
    {
        try {
            $clean = EventRegistry::validate($name, 'client', $properties, $guest);
            if ($clean === null) {
                Log::info('client analytics event rejected', ['event' => $name]);

                return false;
            }
            self::insert($name, $subjectId, 'client', $clean, $context, $clientEventId, null, 'learner');

            return true;
        } catch (\Throwable $e) {
            Log::warning('analytics write failed', ['event' => $name, 'error' => $e::class]);

            return false;
        }
    }

    /** Events from a pre-account guest bundle follow the account's subject_id after commit (docs/04 §7). */
    public static function adoptGuestSubject(string $guestSubjectId, string $accountSubjectId): void
    {
        if ($guestSubjectId === $accountSubjectId) {
            return;
        }
        try {
            DB::update('UPDATE analytics_events SET subject_id = ? WHERE subject_id = ?', [$accountSubjectId, $guestSubjectId]);
        } catch (\Throwable $e) {
            Log::warning('analytics adopt failed', ['error' => $e::class]);
        }
    }

    private static function insert(string $name, string $subjectId, string $source, array $properties, array $context, ?string $clientEventId, ?string $dedupeKey, string $actor): void
    {
        $eventId = $dedupeKey !== null ? Uuid::uuid5(self::NAMESPACE, $name.'|'.$dedupeKey)->toString() : Ids::new();
        $now = Clock::now();

        DB::insert(
            'INSERT INTO analytics_events (event_id, client_event_id, subject_id, event_name, actor, source, occurred_at, received_at,
                local_date, device_class, session_ref, content_version_id, roadmap_version_id, topic_key, mode, properties, schema_version, app_version)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, 1, ?)
             ON CONFLICT DO NOTHING',
            [
                $eventId, $clientEventId, $subjectId, $name, $actor, $source, $now, $now,
                $context['local_date'] ?? null, $context['device_class'] ?? 'unknown', $context['session_ref'] ?? null,
                $context['content_version_id'] ?? null, $context['roadmap_version_id'] ?? null, $context['topic_key'] ?? null,
                $context['mode'] ?? null, json_encode((object) $properties), config('app.version', 'dev'),
            ],
        );
    }
}
