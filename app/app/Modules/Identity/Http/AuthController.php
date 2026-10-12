<?php

namespace App\Modules\Identity\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Analytics\EventRecorder;
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
    public function __construct(private ProfileService $profile) {}

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

    public function me(Request|User $subject): JsonResponse|array
    {
        $user = $subject instanceof Request ? $subject->user() : $subject;
        $prefs = $this->profile->preferences((string) $user->id);
        $payload = [
            'id' => $user->id,
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'time_zone' => $prefs['time_zone'] ?? null,
            'onboarding_completed' => $prefs !== null,
            'deletion' => ['pending' => $user->status === 'pending_deletion'],
            'subject_id' => $user->analytics_subject_id,
        ];

        return $subject instanceof Request ? response()->json(['user' => $payload]) : $payload;
    }
}
