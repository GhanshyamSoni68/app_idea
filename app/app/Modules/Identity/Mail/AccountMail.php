<?php

namespace App\Modules\Identity\Mail;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Transactional account email. A mail outage never blocks learning or sign-in (PRD §12): failures are logged
 * without the address or the token, and the learner can request another message.
 */
final class AccountMail
{
    public static function verification(string $email, string $token): void
    {
        $link = rtrim((string) config('app.url'), '/').'/verify?token='.$token;
        self::send($email, 'Confirm your DevStep email address', "Confirm your email address for DevStep by opening this link:\n\n{$link}\n\nIt works once and expires in 24 hours. If you did not create an account, ignore this message.");
    }

    public static function passwordReset(string $email, string $token): void
    {
        $link = rtrim((string) config('app.url'), '/').'/reset?token='.$token;
        self::send($email, 'Reset your DevStep password', "Choose a new DevStep password by opening this link:\n\n{$link}\n\nIt works once and expires in 60 minutes. Using it signs you out everywhere. If you did not ask for this, ignore this message and your password stays as it is.");
    }

    private static function send(string $email, string $subject, string $body): void
    {
        try {
            Mail::raw($body, fn ($m) => $m->to($email)->subject($subject));
        } catch (\Throwable $e) {
            Log::warning('account email failed', ['error' => $e::class]);
        }
    }
}
