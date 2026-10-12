<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Layered idempotency (docs/04 §5.3-5.4). The key row is written inside the same transaction as the
 * work, so a failure rolls the key back with it. Correctness never depends on the key still existing:
 * unique constraints and conditional updates are the permanent layer.
 */
final class Idempotency
{
    /**
     * @param  callable(): array{0:int,1:array}  $work  Returns [status, body]. Runs at most once per key.
     */
    public static function run(Request $request, string $userId, string $scope, array $payload, callable $work): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if (strlen($key) < 8 || strlen($key) > 128) {
            throw new ApiException('idempotency_key_required', 'This request needs an Idempotency-Key header of 8 to 128 characters.', 400);
        }

        $hash = hash('sha256', json_encode(self::canonical($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $now = Clock::now();

        return DB::transaction(function () use ($userId, $scope, $key, $hash, $now, $work) {
            $id = Ids::new();
            $inserted = DB::selectOne(
                'INSERT INTO idempotency_keys (id, user_id, scope, key, request_hash, state, expires_at, created_at)
                 VALUES (?, ?, ?, ?, ?, \'in_progress\', ?, ?)
                 ON CONFLICT (user_id, scope, key) DO NOTHING RETURNING id',
                [$id, $userId, $scope, $key, $hash, $now->addDays((int) config('devstep.idempotency_ttl_days')), $now],
            );

            if ($inserted === null) {
                $existing = DB::selectOne(
                    'SELECT request_hash, state, response_status, response_body FROM idempotency_keys
                     WHERE user_id = ? AND scope = ? AND key = ?',
                    [$userId, $scope, $key],
                );
                if ($existing === null) {
                    throw new ApiException('request_in_progress', 'This request is already being processed. Try again shortly.', 409, true);
                }
                if ($existing->request_hash !== $hash) {
                    throw new ApiException('idempotency_key_reuse', 'That Idempotency-Key was already used with a different request body.', 422);
                }
                if ($existing->state !== 'completed') {
                    throw new ApiException('request_in_progress', 'This request is already being processed. Try again shortly.', 409, true);
                }

                return response()->json(json_decode($existing->response_body, true), (int) $existing->response_status)
                    ->header('Idempotent-Replayed', 'true');
            }

            [$status, $body] = $work();

            DB::update(
                'UPDATE idempotency_keys SET state = \'completed\', response_status = ?, response_body = ?::jsonb WHERE id = ?',
                [$status, json_encode($body), $id],
            );

            return response()->json($body, $status);
        });
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            $isList = array_is_list($value);
            $out = array_map([self::class, 'canonical'], $value);
            if (! $isList) {
                ksort($out);
            }

            return $out;
        }

        return $value;
    }
}
