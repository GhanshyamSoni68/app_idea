<?php

namespace App\Modules\Generation;

use App\Support\Clock;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The audit trail (docs/16 §6). Every model call is recorded: who answered, how long, how many tokens, what it cost,
 * and how it ended. A logging failure never breaks a generation, and never leaks a secret (messages are trimmed and
 * keys are never part of a message).
 */
final class GenerationLog
{
    public function startRun(string $kind, ?string $userId, string $topic, array $params = [], ?string $parent = null): string
    {
        $id = Ids::new();
        DB::insert(
            'INSERT INTO generation_runs (id, user_id, parent_run_id, kind, status, stage, topic, topic_hash, params, prompt_version, created_at)
             VALUES (?, ?, ?, ?, \'queued\', \'queued\', ?, ?, ?::jsonb, ?, ?)',
            [$id, $userId, $parent, $kind, $topic, hash('sha256', mb_strtolower(trim($topic))), json_encode($params), config('generation.prompt_version'), Clock::now()],
        );

        return $id;
    }

    public function stage(string $runId, string $stage): void
    {
        DB::update("UPDATE generation_runs SET stage = ?, status = 'running', started_at = COALESCE(started_at, ?) WHERE id = ?", [$stage, Clock::now(), $runId]);
    }

    public function finish(string $runId, string $status, ?string $code, ?array $detail, RunTally $tally, int $repairRounds = 0, ?bool $independent = null): void
    {
        DB::update(
            'UPDATE generation_runs SET status = ?, stage = ?, reason_code = ?, reason_detail = ?::jsonb, repair_rounds = ?, verifier_independent = ?,
                input_tokens = ?, output_tokens = ?, cost_usd = ?, finished_at = ? WHERE id = ?',
            [$status, $status, $code, $detail === null ? null : json_encode($detail), $repairRounds, $independent, $tally->inputTokens, $tally->outputTokens, round($tally->costUsd, 4), Clock::now(), $runId],
        );
    }

    /** @param  array<string, mixed>  $row */
    public function call(array $row): void
    {
        try {
            DB::insert(
                'INSERT INTO generation_calls (id, run_id, task, provider, model, attempt, ok, error_kind, error_message, latency_ms, input_tokens, output_tokens,
                    cost_usd, finish_reason, prompt_version, request_hash, payload, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?)',
                [Ids::new(), $row['run_id'] ?? null, $row['task'], $row['provider'], $row['model'], $row['attempt'] ?? 1, $row['ok'] ? 'true' : 'false',
                    $row['error_kind'] ?? null, isset($row['error_message']) ? mb_substr((string) $row['error_message'], 0, 500) : null, $row['latency_ms'] ?? 0,
                    $row['input_tokens'] ?? 0, $row['output_tokens'] ?? 0, round((float) ($row['cost_usd'] ?? 0), 4), $row['finish_reason'] ?? null,
                    config('generation.prompt_version'), $row['request_hash'] ?? null, isset($row['payload']) ? json_encode($row['payload']) : null, Clock::now()],
            );
        } catch (\Throwable $e) {
            Log::warning('generation call could not be logged', ['error' => $e->getMessage()]);
        }
    }

    /** Spend in the last 24 hours across all runs. */
    public function spentToday(): float
    {
        return (float) DB::selectOne("SELECT COALESCE(SUM(cost_usd), 0) AS s FROM generation_calls WHERE created_at > ?", [Clock::now()->subDay()])->s;
    }

    public function userRunsToday(string $userId): int
    {
        return (int) DB::selectOne("SELECT COUNT(*) AS n FROM generation_runs WHERE user_id = ? AND kind = 'roadmap' AND created_at > ?", [$userId, Clock::now()->subDay()])->n;
    }

    /** Drops prompts and raw replies older than the retention window. Cost and outcome records stay. */
    public static function purgePayloads(): int
    {
        return DB::update(
            'UPDATE generation_calls SET payload = NULL WHERE payload IS NOT NULL AND created_at < ?',
            [Clock::now()->subDays((int) config('generation.payload_retention_days'))],
        );
    }
}
