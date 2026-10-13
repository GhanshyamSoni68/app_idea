<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Analytics\EventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\BuildsLearners;
use Tests\TestCase;
use ZipArchive;

/**
 * F09: email verification, password recovery, export and deletion (docs/09 §3.5, §8).
 */
class AccountLifecycleTest extends TestCase
{
    use BuildsLearners, RefreshDatabase;

    private string $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publishContent();
        $this->at('2026-10-12T19:30:00Z');
        $this->ledger = sys_get_temp_dir().'/ledger-'.Str::random(8).'.jsonl';
        config(['devstep.deletion_ledger_path' => $this->ledger]);
    }

    protected function tearDown(): void
    {
        $this->tearDownClock();
        @unlink($this->ledger);
        parent::tearDown();
    }

    /** @return list<string> bodies of every message the array mailer has "sent" */
    private function mailBodies(): array
    {
        $transport = Mail::mailer('array')->getSymfonyTransport();

        // Long lines are quoted-printable on the wire; decode as a mail client would.
        return $transport->messages()->map(fn ($m) => quoted_printable_decode($m->getOriginalMessage()->getBody()->bodyToString()))->all();
    }

    private function lastToken(string $path): string
    {
        $all = implode("\n", array_reverse($this->mailBodies()));
        preg_match('#/'.$path.'\?token=([A-Za-z0-9_\-]+)#', $all, $m);
        $this->assertNotEmpty($m[1] ?? null, "no {$path} link was emailed");

        return $m[1];
    }

    public function test_sign_up_sends_a_verification_link_that_works_once_and_expires(): void
    {
        $code = $this->inviteFor('new@example.com');
        $this->postJson('/v1/auth/sign-up', ['email' => 'new@example.com', 'password' => 'a-long-enough-passphrase', 'invite_code' => $code])->assertCreated()->assertJsonPath('user.email_verified', false);
        $token = $this->lastToken('verify');

        $this->postJson('/v1/auth/verification/confirm', ['token' => $token])->assertOk()->assertJsonPath('verified', true);
        $this->assertNotNull(User::where('email_normalised', 'new@example.com')->value('email_verified_at'));
        $this->postJson('/v1/auth/verification/confirm', ['token' => $token])->assertStatus(422)->assertJsonPath('error.code', 'token_invalid');
        $this->assertSame(0, DB::table('auth_tokens')->where('token_hash', $token)->count(), 'only a hash is stored, never the token');

        // A fresh token that is left too long no longer works (24 hours).
        $user = User::where('email_normalised', 'new@example.com')->first();
        DB::table('users')->where('id', $user->id)->update(['email_verified_at' => null]);
        $this->actingAs($user)->postJson('/v1/auth/verification/resend')->assertStatus(202);
        $late = $this->lastToken('verify');
        $this->at('2026-10-13T20:00:00Z');
        $this->postJson('/v1/auth/verification/confirm', ['token' => $late])->assertStatus(422);
    }

    public function test_recovery_does_not_reveal_accounts_and_a_reset_signs_out_everywhere(): void
    {
        $user = $this->newUser('ada@example.com');
        $before = count($this->mailBodies());
        $this->postJson('/v1/auth/recovery', ['email' => 'nobody@example.com'])->assertStatus(202)->assertJsonPath('sent', true);
        $this->assertSame($before, count($this->mailBodies()), 'no email goes to an unknown address');
        $this->postJson('/v1/auth/recovery', ['email' => 'ADA@example.com'])->assertStatus(202)->assertJsonPath('sent', true);
        $token = $this->lastToken('reset');

        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()]);
        $this->postJson('/v1/auth/recovery/confirm', ['token' => $token, 'password' => 'short'])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->postJson('/v1/auth/recovery/confirm', ['token' => $token, 'password' => 'a-brand-new-passphrase'])->assertOk();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count(), 'every session is revoked');
        $this->postJson('/v1/auth/recovery/confirm', ['token' => $token, 'password' => 'another-passphrase-here'])->assertStatus(422);

        $this->postJson('/v1/auth/sign-in', ['email' => 'ada@example.com', 'password' => 'correct horse battery'])->assertStatus(401);
        $this->postJson('/v1/auth/sign-in', ['email' => 'ada@example.com', 'password' => 'a-brand-new-passphrase'])->assertOk();
    }

    public function test_export_contains_the_learners_own_data_and_never_secrets_or_answer_keys(): void
    {
        $user = $this->newUser('ada@example.com');
        $this->claimSampleFor($user);
        $other = $this->newUser('eve@example.com');

        $this->actingAs($user);
        $key = (string) Str::uuid();
        $res = $this->withHeader('Idempotency-Key', $key)->postJson('/v1/me/export')->assertStatus(202);
        $export = $res->json('export');
        $this->assertSame('ready', $export['status']);
        $this->withHeader('Idempotency-Key', $key)->postJson('/v1/me/export')->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, DB::table('export_requests')->where('user_id', $user->id)->count());

        $this->getJson("/v1/me/export/{$export['id']}")->assertOk()->assertJsonPath('export.status', 'ready');
        $download = $this->get($export['download_url'])->assertOk();
        $this->assertSame('application/zip', $download->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));

        $path = tempnam(sys_get_temp_dir(), 'z');
        file_put_contents($path, $download->getContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        sort($names);
        $this->assertSame(['README.txt', 'account.json', 'learning.json'], $names);
        $account = json_decode($zip->getFromName('account.json'), true);
        $learning = json_decode($zip->getFromName('learning.json'), true);
        $raw = $zip->getFromName('account.json').$zip->getFromName('learning.json');
        $zip->close();
        @unlink($path);

        $this->assertSame('ada@example.com', $account['account']['email']);
        $this->assertCount(3, $learning['attempts']);
        $this->assertSame(['choice' => 'c'], $learning['attempts'][0]['response'], 'the learner\'s own answers are included');
        $this->assertNotEmpty($learning['skill_evidence']);
        $this->assertStringNotContainsString('password', $raw);
        $this->assertStringNotContainsString('$2y$', $raw, 'no password hash');
        $this->assertStringNotContainsString('"correct": true', $raw, 'no answer keys');
        $this->assertStringNotContainsString('"hints"', $raw, 'no authored hints');
        $this->assertStringNotContainsString('"reveal"', $raw, 'no authored solutions');
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $account['preferences']['available_days'], 'days are readable weekdays, not a bitmask');
        $this->assertStringNotContainsString('eve@example.com', $raw);

        // Nobody else can fetch it; and it expires.
        $this->actingAs($other)->getJson("/v1/me/export/{$export['id']}")->assertStatus(404);
        $this->actingAs($other)->get($export['download_url'])->assertStatus(404);
        $this->actingAs($user);
        $this->at('2026-10-13T20:00:00Z');
        $this->getJson("/v1/me/export/{$export['id']}")->assertOk()->assertJsonPath('export.status', 'expired');
        $this->get($export['download_url'])->assertStatus(404);
        $this->artisan('housekeeping:purge')->assertSuccessful();
        $this->assertNull(DB::table('export_requests')->where('id', $export['id'])->value('file'));
    }

    public function test_deletion_has_a_cancellable_grace_then_removes_everything_and_survives_a_restore(): void
    {
        $user = $this->newUser('ada@example.com');
        $this->claimSampleFor($user);
        $subject = $user->analytics_subject_id;
        EventRecorder::server('account_created', $subject, ['was_guest' => false, 'origin' => 'direct']);
        $this->assertGreaterThan(0, DB::table('analytics_events')->where('subject_id', $subject)->count());

        $this->actingAs($user);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->deleteJson('/v1/me', ['password' => 'wrong'])->assertStatus(422)->assertJsonPath('error.code', 'password_incorrect');
        $this->assertSame(0, DB::table('deletion_requests')->count(), 'a wrong password starts nothing');
        $key = (string) Str::uuid();
        $req = $this->withHeader('Idempotency-Key', $key)->deleteJson('/v1/me', ['password' => 'correct horse battery'])->assertStatus(202);
        $this->assertSame('pending', $req->json('deletion.status'));
        $this->withHeader('Idempotency-Key', $key)->deleteJson('/v1/me', ['password' => 'correct horse battery'])->assertHeader('Idempotent-Replayed', 'true');

        // Limited sign-in: only cancel and export remain. (Real requests reload the user each time; tests must too.)
        $this->actingAs(User::findOrFail($user->id));
        $this->getJson('/v1/me')->assertOk()->assertJsonPath('user.deletion.pending', true);
        $this->getJson('/v1/today')->assertStatus(403)->assertJsonPath('error.code', 'deletion_pending');
        $this->postJson('/v1/sessions', ['mode' => 'practise'])->assertStatus(403);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/v1/me/export')->assertStatus(202);

        // Cancel: exactly as before.
        $this->postJson('/v1/me/deletion/cancel')->assertOk();
        $this->actingAs(User::findOrFail($user->id));
        $this->getJson('/v1/today')->assertOk();
        $this->postJson('/v1/me/deletion/cancel')->assertStatus(409)->assertJsonPath('error.code', 'no_pending_deletion');

        // Ask again, wait out the grace, and the nightly purge removes the account and its analytics events.
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->deleteJson('/v1/me', ['password' => 'correct horse battery'])->assertStatus(202);
        $this->at('2026-10-18T10:00:00Z');
        $this->artisan('accounts:purge')->expectsOutput('Purged 0 account(s).')->assertSuccessful();
        $this->assertSame(1, DB::table('users')->count());
        $this->at('2026-10-20T10:00:00Z'); // past 7 days
        $this->artisan('accounts:purge')->expectsOutput('Purged 1 account(s).')->assertSuccessful();

        foreach (['users', 'attempts', 'skill_evidence', 'review_schedule', 'learning_sessions', 'roadmap_enrolments', 'topic_progress', 'learning_preferences', 'goals', 'export_requests'] as $t) {
            $this->assertSame(0, DB::table($t)->count(), "{$t} is empty after the purge");
        }
        $this->assertSame(0, DB::table('analytics_events')->where('subject_id', $subject)->count(), 'the learner\'s analytics events are deleted too');
        $done = DB::table('deletion_requests')->where('status', 'completed')->first();
        $this->assertNull($done->user_ref, 'only an anonymous exclusion record remains');

        $events = array_map(fn ($l) => json_decode($l, true)['event'], file($this->ledger, FILE_IGNORE_NEW_LINES));
        $this->assertSame(['requested', 'cancelled', 'requested', 'purged'], $events, 'the ledger lives outside the database');

        // A restore from an older backup brings the account back; replaying the ledger deletes it again.
        $this->newUserWithIds(Str::before($done->id, '-') === '' ? '' : (string) json_decode(file($this->ledger)[3], true)['user_ref'], $subject);
        EventRecorder::server('account_created', $subject, ['was_guest' => false, 'origin' => 'direct']);
        $this->assertSame(1, DB::table('users')->count());
        $this->artisan('accounts:replay-ledger')->expectsOutput('Re-applied 1 purge(s) from the ledger.')->assertSuccessful();
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('analytics_events')->where('subject_id', $subject)->count());
    }

    private function newUserWithIds(string $id, string $subject): void
    {
        DB::insert('INSERT INTO users (id, email, email_normalised, password_hash, analytics_subject_id) VALUES (?, ?, ?, ?, ?)', [$id, 'back@example.com', 'back@example.com', 'x', $subject]);
    }
}
