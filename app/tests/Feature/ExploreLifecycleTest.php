<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Generation\Llm\LlmRequest;
use App\Modules\Generation\Providers\FakeProvider;
use App\Modules\Identity\DeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsLearners;
use Tests\TestCase;

/** After a path exists: labels, human review, retiring a mission, and what happens to it when the learner leaves. */
class ExploreLifecycleTest extends TestCase
{
    use BuildsLearners, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeProvider::reset();
        config(['generation.enabled' => true, 'generation.retries' => 0, 'generation.require_independent_verifier' => false]);
        $this->at('2026-10-12T19:30:00Z');
    }

    protected function tearDown(): void
    {
        $this->tearDownClock();
        FakeProvider::reset();
        parent::tearDown();
    }

    private function pathFor(User $user, string $topic = 'Docker networking basics'): string
    {
        $id = $this->actingAs($user)->postJson('/v1/explore', ['topic' => $topic, 'ack' => true])->assertStatus(202)->json('id');
        $this->postJson("/v1/explore/{$id}/start")->assertSuccessful();

        return $id;
    }

    private function review(array $args): int
    {
        return Artisan::call('generation:review', $args);
    }

    public function test_the_app_can_tell_when_practice_is_ai_made_and_not_yet_reviewed(): void
    {
        $this->publishContent();
        $ada = $this->newUser();
        $this->actingAs($ada)->postJson('/v1/enrolments')->assertCreated();
        $session = $this->postJson('/v1/sessions', ['mode' => 'practise'])->assertCreated();
        $this->assertFalse($session->json('session.unreviewed_ai'), 'authored practice is not labelled');
        $enrolments = $this->getJson('/v1/enrolments')->json('enrolments');
        $this->assertSame([false, true], [$enrolments[0]['made_for_you'], $enrolments[0]['reviewed']]);

        $this->pathFor($ada);
        $sid = $this->postJson('/v1/sessions', ['mode' => 'practise', 'if_open' => 'suspend'])->assertCreated()->json('session');
        $this->assertTrue($sid['unreviewed_ai']);
        $active = $this->getJson('/v1/enrolments')->json('enrolments')[0];
        $this->assertSame([true, false], [$active['made_for_you'], $active['reviewed']]);
        $view = $this->getJson("/v1/enrolments/{$active['id']}")->json();
        $this->assertTrue($view['roadmap']['made_for_you']);
        $this->assertFalse($view['roadmap']['reviewed']);
        $this->assertStringContainsString('A person has not reviewed it yet', $view['roadmap']['coverage_note']);
    }

    public function test_review_needs_a_named_person_who_tried_the_exercise(): void
    {
        $ada = $this->newUser();
        $this->pathFor($ada);
        $a = substr(DB::table('generated_artifacts')->where('kind', 'mission')->orderBy('created_at')->value('id'), 0, 10);

        $this->assertSame(1, $this->review(['artifact' => $a, '--approve' => true, '--reviewer' => 'ai-generated', '--tried-exercise' => true]));
        $this->assertSame(1, $this->review(['artifact' => $a, '--approve' => true, '--reviewer' => 'Priya']), 'trying the exercise is required');
        $this->assertSame(1, $this->review(['artifact' => $a, '--approve' => true, '--reject' => true, '--reviewer' => 'Priya', '--tried-exercise' => true]));
        $this->assertSame(0, DB::table('generated_artifacts')->where('status', 'reviewed')->count());
        $this->assertSame(1, $this->review(['artifact' => 'zzzz', '--approve' => true, '--reviewer' => 'Priya', '--tried-exercise' => true]));
    }

    public function test_a_path_is_reviewed_only_when_every_mission_in_it_is_and_the_cap_lifts_with_it(): void
    {
        $ada = $this->newUser();
        $this->pathFor($ada);
        $enrolmentId = $this->getJson('/v1/enrolments')->json('enrolments.0.id');
        $missions = DB::table('generated_artifacts')->where('kind', 'mission')->orderBy('created_at')->pluck('id');
        $this->assertCount(6, $missions);

        foreach ($missions->take(5) as $id) {
            $this->assertSame(0, $this->review(['artifact' => substr($id, 0, 12), '--approve' => true, '--reviewer' => 'Priya', '--tried-exercise' => true, '--notes' => 'worked all of it']));
        }
        $this->assertFalse($this->getJson("/v1/enrolments/{$enrolmentId}")->json('roadmap.reviewed'), 'one mission is still unreviewed');
        $this->assertSame(0, $this->review(['artifact' => substr($missions[5], 0, 12), '--approve' => true, '--reviewer' => 'Priya', '--tried-exercise' => true]));
        $this->assertTrue($this->getJson("/v1/enrolments/{$enrolmentId}")->json('roadmap.reviewed'));
        $this->assertSame(0, DB::table('content_releases')->where('origin', 'generated')->where('review_status', 'unreviewed')->count());
        $this->assertSame('Priya', DB::table('content_versions')->where('content_key', 'like', 'g%')->where('content_kind', 'mission')->value('reviewed_by'));
        $this->assertSame(6, DB::table('generated_artifacts')->where('status', 'reviewed')->count());
        $this->postJson('/v1/sessions', ['mode' => 'practise', 'if_open' => 'suspend'])->assertCreated()->assertJsonPath('session.unreviewed_ai', false);
    }

    public function test_a_rejected_mission_is_retired_so_no_new_session_uses_it(): void
    {
        $ada = $this->newUser();
        $this->pathFor($ada);
        $enrolmentId = $this->getJson('/v1/enrolments')->json('enrolments.0.id');
        $first = DB::table('generated_artifacts')->where('kind', 'mission')->orderBy('created_at')->first();
        $this->assertTrue(collect($this->getJson("/v1/enrolments/{$enrolmentId}")->json('modules.0.topics'))->every(fn ($t) => $t['has_content']));

        $this->assertSame(0, $this->review(['artifact' => substr($first->id, 0, 12), '--reject' => true, '--notes' => 'a claim in it is wrong']));

        $topics = collect($this->getJson("/v1/enrolments/{$enrolmentId}")->json('modules.0.topics'));
        $this->assertFalse($topics->first()['has_content'], 'the topic goes back to being prepared');
        $this->assertSame('rejected', DB::table('generated_artifacts')->where('id', $first->id)->value('status'));
        $this->assertSame(0, DB::table('content_versions')->where('content_key', 'like', $first->content_key.'%')->where('status', 'published')->count());
    }

    public function test_deleting_the_account_removes_the_learners_paths_and_leaves_everyone_elses_alone(): void
    {
        $this->publishContent();
        $ada = $this->newUser('ada@example.com');
        $bob = $this->newUser('bob@example.com');
        $this->pathFor($ada, 'Docker networking basics');
        $this->pathFor($bob, 'Queues and retries');
        $adaRoadmap = DB::table('roadmaps')->where('owner_user_id', $ada->id)->value('id');
        $bobRoadmap = DB::table('roadmaps')->where('owner_user_id', $bob->id)->value('id');
        $adaKeys = DB::table('roadmap_topics')->whereIn('roadmap_version_id', DB::table('roadmap_versions')->where('roadmap_id', $adaRoadmap)->pluck('id'))->pluck('topic_key')->all();
        $bobKeys = DB::table('roadmap_topics')->whereIn('roadmap_version_id', DB::table('roadmap_versions')->where('roadmap_id', $bobRoadmap)->pluck('id'))->pluck('topic_key')->all();
        $this->assertCount(6, $adaKeys);
        $authoredBefore = DB::table('content_versions')->whereNotIn('release_id', DB::table('content_releases')->where('origin', 'generated')->pluck('id'))->count();
        $this->assertGreaterThan(0, DB::table('generation_runs')->where('user_id', $ada->id)->whereNotNull('topic')->count());

        DeletionService::erase($ada->id, (string) $ada->analytics_subject_id);

        $this->assertNull(DB::table('roadmaps')->where('id', $adaRoadmap)->first(), "Ada's path is gone");
        $this->assertNotNull(DB::table('roadmaps')->where('id', $bobRoadmap)->first(), "Bob's path is untouched");
        $this->assertSame(0, DB::table('generated_artifacts')->where('owner_user_id', $ada->id)->count());
        $this->assertSame(0, DB::table('generation_runs')->whereNull('user_id')->whereNotNull('topic')->count(), 'no learner-typed text outlives the account');
        $this->assertSame($authoredBefore, DB::table('content_versions')->whereNotIn('release_id', DB::table('content_releases')->where('origin', 'generated')->pluck('id'))->count(), 'authored content is untouched');
        $this->assertSame(6, DB::table('content_versions')->where('content_kind', 'mission')->whereIn('release_id', DB::table('content_releases')->where('origin', 'generated')->pluck('id'))->count(), "only Bob's six missions remain");
        $this->assertSame(0, DB::table('skills')->whereIn('skill_key', $adaKeys)->count(), "Ada's skills are gone");
        $this->assertSame(6, DB::table('skills')->whereIn('skill_key', $bobKeys)->count(), "Bob's skills remain");
    }
}
