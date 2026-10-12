<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsLearners;
use Tests\TestCase;

/**
 * PRD §12 / docs/09 §5: another learner must never reach someone else's attempts, drafts or sessions.
 * Cross-owner access returns 404, never 403, so existence is not revealed.
 */
class AuthorisationMatrixTest extends TestCase
{
    use BuildsLearners, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publishContent();
        $this->at('2026-10-12T19:30:00Z');
    }

    protected function tearDown(): void
    {
        $this->tearDownClock();
        parent::tearDown();
    }

    public function test_every_owned_resource_route_returns_404_to_another_learner(): void
    {
        $owner = $this->newUser('owner@example.com');
        $intruder = $this->newUser('intruder@example.com');
        $this->claimSampleFor($owner);
        $this->at('2026-10-13T18:00:00Z');
        $sid = $this->postJson('/v1/sessions', ['mode' => 'practise'])->assertCreated()->json('session.id');
        $enrolmentId = DB::table('roadmap_enrolments')->where('user_id', $owner->id)->value('id');
        $step = DB::table('learning_sessions')->where('id', $sid)->first();
        $plan = json_decode($step->plan, true);
        $itemCv = $plan['steps'][0]['item_cv'];

        $this->actingAs($intruder);
        $uuid = (string) Str::uuid();
        $requests = [
            ['GET', "/v1/sessions/{$sid}", []],
            ['PUT', "/v1/sessions/{$sid}/draft", ['base_revision' => 0, 'save_id' => $uuid, 'step_index' => 0, 'data' => []]],
            ['POST', "/v1/sessions/{$sid}/hints", ['step_index' => 0, 'hint_number' => 1]],
            ['POST', "/v1/sessions/{$sid}/reveal", ['step_index' => 0, 'confirm' => true]],
            ['POST', "/v1/sessions/{$sid}/attempts", ['step_index' => 0, 'assessment_item_id' => $itemCv, 'content_version_id' => $itemCv, 'answer' => ['choice' => 'b']]],
            ['POST', "/v1/sessions/{$sid}/complete", ['base_revision' => 0]],
            ['GET', "/v1/enrolments/{$enrolmentId}", []],
        ];
        foreach ($requests as [$method, $url, $body]) {
            $res = $this->withHeader('Idempotency-Key', (string) Str::uuid())->json($method, $url, $body);
            $res->assertStatus(404);
            $this->assertSame('not_found', $res->json('error.code'), "{$method} {$url}");
        }

        // The intruder's own views contain none of the owner's data.
        $this->getJson('/v1/evidence')->assertOk()->assertJsonCount(0, 'evidence');
        $this->assertSame([], $this->getJson('/v1/enrolments')->json('enrolments'));
        $this->assertSame('first_run', $this->getJson('/v1/today')->json('situation'));

        // Nothing changed for the owner.
        $this->assertSame('open', DB::table('learning_sessions')->where('id', $sid)->value('status'));
        $this->assertSame(0, DB::table('attempts')->where('session_id', $sid)->count());
    }

    public function test_anonymous_requests_to_learner_routes_get_401_in_the_standard_envelope(): void
    {
        foreach (['/v1/today', '/v1/evidence', '/v1/me', '/v1/enrolments'] as $url) {
            $res = $this->getJson($url)->assertStatus(401);
            $this->assertSame('unauthenticated', $res->json('error.code'));
            $this->assertNotEmpty($res->json('error.request_id'));
            $this->assertNotEmpty($res->headers->get('X-Request-Id'));
        }
    }

    public function test_the_database_itself_refuses_cross_learner_references(): void
    {
        $a = $this->newUser('a@example.com');
        $b = $this->newUser('b@example.com');
        $this->claimSampleFor($a);
        $sessionId = DB::table('learning_sessions')->where('user_id', $a->id)->value('id');
        $cv = DB::table('content_versions')->where('content_kind', 'assessment_item')->value('id');

        $this->expectException(QueryException::class);
        // Composite FK (session_id, user_id): learner B cannot attach an attempt to learner A's session.
        DB::insert(
            "INSERT INTO attempts (id, user_id, session_id, content_version_id, step_key, attempt_no, client_attempt_id, purpose, origin, response, evidence_basis, assistance, submitted_at)
             VALUES (?, ?, ?, ?, 'x', 1, ?, 'in_mission', 'session', '{}', 'auto_scored', 'none', now())",
            [(string) Str::uuid(), $b->id, $sessionId, $cv, (string) Str::uuid()],
        );
    }

    public function test_invites_are_single_use_and_bound_to_the_email(): void
    {
        $code = $this->inviteFor('first@example.com');
        $this->postJson('/v1/auth/sign-up', ['email' => 'other@example.com', 'password' => 'a-long-enough-passphrase', 'invite_code' => $code])
            ->assertStatus(422)->assertJsonPath('error.code', 'invite_invalid');
        $this->postJson('/v1/auth/sign-up', ['email' => 'first@example.com', 'password' => 'short', 'invite_code' => $code])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->assertNull(DB::table('invites')->value('redeemed_at'), 'a failed attempt does not burn the code');
        $this->postJson('/v1/auth/sign-up', ['email' => 'First@Example.com', 'password' => 'a-long-enough-passphrase', 'invite_code' => strtolower($code)])->assertCreated();
        $this->postJson('/v1/auth/sign-up', ['email' => 'first@example.com', 'password' => 'a-long-enough-passphrase', 'invite_code' => $code])->assertStatus(422);
        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_me_reports_the_account_and_onboarding_state(): void
    {
        $this->actingAs($this->newUser('ada@example.com', 'Europe/London'));
        $this->getJson('/v1/me')->assertOk()
            ->assertJsonPath('user.email', 'ada@example.com')
            ->assertJsonPath('user.onboarding_completed', true)
            ->assertJsonPath('user.time_zone', 'Europe/London');
        $this->actingAs($this->newUser('new@example.com', onboard: false));
        $this->getJson('/v1/me')->assertOk()->assertJsonPath('user.onboarding_completed', false);
    }

    public function test_sign_in_and_out(): void
    {
        $this->newUser('ada@example.com');
        $this->postJson('/v1/auth/sign-in', ['email' => 'ada@example.com', 'password' => 'wrong'])->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');
        $this->postJson('/v1/auth/sign-in', ['email' => 'ADA@example.com', 'password' => 'correct horse battery'])->assertOk()->assertJsonPath('user.email', 'ada@example.com');
    }
}
