<?php

namespace App\Modules\Identity;

use App\Support\Clock;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-time tokens for email verification and password reset (docs/09 §3.5). Only a hash is stored; a token is
 * random (at least 128 bits), single-use, short-lived, and issuing a new one voids the earlier unused ones.
 */
final class AccountTokens
{
    public const TTL_MINUTES = ['email_verification' => 60 * 24, 'password_reset' => 60, 'email_change' => 60];

    public function issue(string $userId, string $purpose): string
    {
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = Clock::now();
        DB::transaction(function () use ($userId, $purpose, $raw, $now) {
            DB::update('UPDATE auth_tokens SET used_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL', [$now, $userId, $purpose]);
            DB::insert(
                'INSERT INTO auth_tokens (id, user_id, purpose, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [Ids::new(), $userId, $purpose, hash('sha256', $raw), $now->addMinutes(self::TTL_MINUTES[$purpose]), $now],
            );
        });

        return $raw;
    }

    /** Single use by one conditional update. Returns the user id, or null when the token is unknown, used or expired. */
    public function consume(string $raw, string $purpose): ?string
    {
        $row = DB::selectOne(
            'UPDATE auth_tokens SET used_at = ? WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > ? RETURNING user_id',
            [Clock::now(), hash('sha256', Str::of($raw)->trim()->toString()), $purpose, Clock::now()],
        );

        return $row->user_id ?? null;
    }
}
