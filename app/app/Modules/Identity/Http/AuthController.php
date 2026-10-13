<?php

namespace App\Modules\Identity\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Analytics\EventRecorder;
use App\Modules\Identity\AccountTokens;
use App\Modules\Identity\DeletionService;
use App\Modules\Identity\Mail\AccountMail;
use App\Modules\Profile\ProfileService;
use App\Support\ApiException;
use App\Support\Clock;
use App\Support\Ids;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/** `identity` module: invite-only sign-up, sign-in and sign-out with session cookies (docs/02, docs/09). */
class AuthController extends Controller
{
    public function __construct(private ProfileService $profile, private AccountTokens $tokens, private DeletionService $deletion) {}

    public function signUp(Request $request): JsonResponse
    {
        $password = Password::min(12);
        if (app()->isProduction()) {
            $password = $password->uncompromised();
        }
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:128', $password],
            'invite_code' => ['required', 'string', 'max:64'],
            'guest_subject_id' => ['nullable', 'uuid'],
        ]);
        $email = Str::lower(trim($data['email']));
        $now = Clock::now();
        $code = Str::upper(trim($data['invite_code']));

        $created = DB::transaction(function () use ($data, $email, $now, $code) {
            $invalid = new ApiException('invite_invalid', 'That invite code is not valid for this email address. Ask the person who invited you for a new one.', 422);
            if (DB::selectOne('SELECT 1 FROM users WHERE email_normalised = ?', [$email])) {
                throw $invalid;
            }

            $guestSubject = $data['guest_subject_id'] ?? null;
            $subjectId = ($guestSubject && ! DB::selectOne('SELECT 1 FROM users WHERE analytics_subject_id = ?', [$guestSubject])) ? $guestSubject : Ids::new();
            $userId = Ids::new();
            DB::insert(
                'INSERT INTO users (id, email, email_normalised, password_hash, analytics_subject_id, last_seen_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$userId, $data['email'], $email, Hash::make($data['password']), $subjectId, $now, $now, $now],
            );
            // Redeemed by one conditional update, so a code works once (docs/04 §3.1). If it matches no
            // row the whole transaction, including the new user, rolls back.
            $invite = DB::selectOne(
                'UPDATE invites SET redeemed_at = ?, redeemed_by = ? WHERE code_hash = ? AND redeemed_at IS NULL AND expires_at > ? AND email_normalised = ? RETURNING id',
                [$now, $userId, hash('sha256', $code), $now, $email],
            );
            if (! $invite) {
                throw $invalid;
            }

            return ['user_id' => $userId, 'subject_id' => $subjectId, 'invite_id' => $invite->id, 'was_guest' => $guestSubject === $subjectId];
        });

        EventRecorder::server('invitation_redeemed', $created['subject_id'], ['invite_id' => $created['invite_id']]);
        EventRecorder::server('account_created', $created['subject_id'], ['was_guest' => $created['was_guest'], 'origin' => $created['was_guest'] ? 'sample_scenario' : 'invitation']);

        $user = User::findOrFail($created['user_id']);
        AccountMail::verification($user->email, $this->tokens->issue($user->id, 'email_verification'));
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $this->me($user)], 201);
    }

    public function signIn(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'max:254'], 'password' => ['required', 'string', 'max:128']]);
        $email = Str::lower(trim($data['email']));
        if (! Auth::attempt(['email_normalised' => $email, 'password' => $data['password']])) {
            throw new ApiException('invalid_credentials', 'That email and password do not match.', 401);
        }
        $request->session()->regenerate();
        $user = $request->user();
        DB::update('UPDATE users SET last_seen_at = ? WHERE id = ?', [Clock::now(), $user->id]);

        return response()->json(['user' => $this->me($user)]);
    }

    public function signOut(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['signed_out' => true]);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->me($request->user())]);
    }

    /** @return array<string, mixed> */
    private function me(User $user): array
    {
        $prefs = $this->profile->preferences((string) $user->id);

        return [
            'id' => $user->id,
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'time_zone' => $prefs['time_zone'] ?? null,
            'onboarding_completed' => $prefs !== null,
            'deletion' => $this->deletionInfo($user),
            'subject_id' => $user->analytics_subject_id,
        ];
    }

    /** @return array{pending: bool, effective_at?: string} */
    private function deletionInfo(User $user): array
    {
        if ($user->status !== 'pending_deletion') {
            return ['pending' => false];
        }
        $d = $this->deletion->pending($user);

        return ['pending' => true, 'effective_at' => $d->effective_at ?? null];
    }

    /** Confirms the address with the single-use token from the verification email. */
    public function confirmVerification(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:200']]);
        $userId = $this->tokens->consume($data['token'], 'email_verification');
        if (! $userId) {
            throw new ApiException('token_invalid', 'That link has expired or was already used. You can ask for a new one from Settings.', 422);
        }
        DB::update('UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?), updated_at = ? WHERE id = ?', [Clock::now(), Clock::now(), $userId]);

        return response()->json(['verified' => true]);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->email_verified_at === null) {
            AccountMail::verification($user->email, $this->tokens->issue($user->id, 'email_verification'));
        }

        return response()->json(['sent' => $user->email_verified_at === null], 202);
    }

    /** Same response whether or not the address exists, so this cannot be used to find accounts (docs/09). */
    public function recovery(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254']]);
        $user = User::where('email_normalised', Str::lower(trim($data['email'])))->where('status', 'active')->first();
        if ($user) {
            AccountMail::passwordReset($user->email, $this->tokens->issue($user->id, 'password_reset'));
        }

        return response()->json(['sent' => true], 202);
    }

    /** Sets a new password with a single-use token, and signs the account out everywhere. */
    public function recoveryConfirm(Request $request): JsonResponse
    {
        $password = Password::min(12);
        if (app()->isProduction()) {
            $password = $password->uncompromised();
        }
        $data = $request->validate(['token' => ['required', 'string', 'max:200'], 'password' => ['required', 'string', 'max:128', $password]]);
        $userId = $this->tokens->consume($data['token'], 'password_reset');
        if (! $userId) {
            throw new ApiException('token_invalid', 'That link has expired or was already used. Ask for a new one.', 422);
        }
        DB::transaction(function () use ($userId, $data) {
            DB::update('UPDATE users SET password_hash = ?, email_verified_at = COALESCE(email_verified_at, ?), updated_at = ? WHERE id = ?', [Hash::make($data['password']), Clock::now(), Clock::now(), $userId]);
            DB::delete('DELETE FROM sessions WHERE user_id = ?', [$userId]); // using the link signs out every device
        });

        return response()->json(['reset' => true]);
    }
}
