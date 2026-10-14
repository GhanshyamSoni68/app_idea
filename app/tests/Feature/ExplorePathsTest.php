<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalogue\ContentPublisher;
use App\Modules\Generation\GenerationService;
use App\Modules\Generation\Pipeline\ContentAssembler;
use App\Modules\Generation\ProviderManager;
use App\Modules\Generation\Providers\FakeProvider;
use App\Modules\Roadmap\RoadmapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsLearners;
use Tests\TestCase;

/** Personal paths (docs/16 §7, §8): who can see them, how focus moves between paths, and the evidence cap on unreviewed content. */
class ExplorePathsTest extends TestCase
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

    /** Generate and publish a personal path for a learner, the way the Explore flow will. @return array{slug:string, topics:int} */
    private function makePathFor(User $user, string $topic = 'Caching basics', int $missions = 2): array
    {
        $svc = GenerationService::make(new ProviderManager(config('generation'), fn () => null));
        $planned = $svc->planRoadmap($user->id, $topic);
        $built = [];
        foreach (array_slice($planned['plan']['topics'], 0, $missions) as $t) {
            $r = $svc->draftMission($user->id, $planned['run_id'], $planned, $t['key']);
            $built[$r['mission']['front']['id']] = $r['mission'];
        }
        (new ContentPublisher)->publish(ContentAssembler::bundle($planned['roadmap'], $built), 'generation', null, ['origin' => 'generated', 'review_status' => 'unreviewed', 'owner_user_id' => $user->id]);

        return ['slug' => $planned['roadmap']['slug'], 'topics' => count($planned['plan']['topics'])];
    }

    private function enrolMain(User $user): object
    {
        [$e] = app(RoadmapService::class)->enrol($user->id, (string) $user->analytics_subject_id);

        return $e;
    }

    // ---- evidence cap ----------------------------------------------------------------------------------------------

    private function demonstrate(): void
    {
        $user = $this->newUser();
        $this->claimSampleFor($user);
        $this->at('2026-10-13T18:00:00Z');
        $res = $this->actingAs($user)->postJson('/v1/sessions', ['mode' => 'practise'])->assertCreated();
        $sid = $res->json('session.id');
        $steps = collect($res->json('steps'))->keyBy('key');
        $this->attempt($sid, $steps['review-0'], ['choice' => 'b'])->assertCreated()->assertJsonPath('outcome', 'correct');
        $this->saveDraft($sid, 1)->assertOk();
    }

    public function test_evidence_from_content_a_person_has_not_reviewed_is_capped_at_practised_and_says_why(): void
    {
        $this->publishContent();
        DB::table('content_releases')->update(['origin' => 'generated', 'review_status' => 'unreviewed']);
        $this->demonstrate();

        $this->assertSame(0, DB::table('skill_evidence')->whereIn('level', ['demonstrated', 'retained'])->count());
        $row = DB::table('skill_evidence')->join('skills', 'skills.id', '=', 'skill_evidence.skill_id')->where('skill_key', 'perf.query-plans')->orderByDesc('observed_at')->first();
        $this->assertSame('practised', $row->level);
        $this->assertContains('ai_generated_unreviewed', json_decode($row->limitations, true));
        $shown = collect($this->getJson('/v1/evidence')->json('evidence'))->firstWhere('skill_key', 'perf.query-plans');
        $this->assertSame('practised', $shown['level']);
        $this->assertContains('ai_generated_unreviewed', $shown['limitations']);
    }

    public function test_authored_content_is_not_capped(): void
    {
        $this->publishContent();
        $this->demonstrate();
        $this->assertSame(1, DB::table('skill_evidence')->where('level', 'demonstrated')->count());
        $this->assertSame(0, DB::table('skill_evidence')->whereRaw("limitations::text LIKE '%ai_generated%'")->count());
    }

    public function test_generated_content_a_person_has_reviewed_is_not_capped_either(): void
    {
        $this->publishContent();
        DB::table('content_releases')->update(['origin' => 'generated', 'review_status' => 'reviewed']);
        $this->demonstrate();
        $this->assertSame(1, DB::table('skill_evidence')->where('level', 'demonstrated')->count());
        $this->assertSame(0, DB::table('skill_evidence')->whereRaw("limitations::text LIKE '%ai_generated%'")->count());
    }

    // ---- ownership -------------------------------------------------------------------------------------------------

    public function test_a_path_made_for_one_learner_is_invisible_to_everyone_else(): void
    {
        $ada = $this->newUser('ada@example.com');
        $bob = $this->newUser('bob@example.com');
        $path = $this->makePathFor($ada);

        $slugs = collect($this->getJson('/v1/roadmaps')->json('roadmaps'))->pluck('slug');
        $this->assertNotContains($path['slug'], $slugs, 'the public catalogue lists only public roadmaps');
        $this->getJson("/v1/roadmaps/{$path['slug']}")->assertNotFound();

        $this->assertSame('roadmap_unavailable', $this->catch404(fn () => app(RoadmapService::class)->startPath($bob->id, (string) $bob->analytics_subject_id, $path['slug'], 'Europe/London')));
        $this->assertSame('roadmap_unavailable', $this->catch404(fn () => app(RoadmapService::class)->enrol($bob->id, (string) $bob->analytics_subject_id, $path['slug'])));
        [$e] = app(RoadmapService::class)->startPath($ada->id, (string) $ada->analytics_subject_id, $path['slug'], 'Europe/London');
        $this->assertSame('active', $e->status);
    }

    private function catch404(callable $f): string
    {
        try {
            $f();
        } catch (\App\Support\ApiException $e) {
            return $e->errorCode ?? $e->getMessage();
        }

        return 'none';
    }

    // ---- focus -----------------------------------------------------------------------------------------------------

    public function test_starting_a_path_parks_the_main_roadmap_with_everything_kept_and_switching_back_restores_it(): void
    {
        $this->publishContent();
        $ada = $this->newUser();
        $main = $this->enrolMain($ada);
        $this->claimSampleFor($ada); // gives Ada a review track and progress on the main roadmap
        $reviewsBefore = DB::table('review_schedule')->where('user_id', $ada->id)->get()->map(fn ($r) => [$r->status, $r->due_on])->all();
        $this->assertNotEmpty($reviewsBefore);
        $path = $this->makePathFor($ada);

        [$e, $created] = app(RoadmapService::class)->startPath($ada->id, (string) $ada->analytics_subject_id, $path['slug'], 'Europe/London');

        $this->assertTrue($created);
        $this->assertSame('active', $e->status);
        $this->assertSame('paused', DB::table('roadmap_enrolments')->where('id', $main->id)->value('status'));
        $this->assertSame($reviewsBefore, DB::table('review_schedule')->where('user_id', $ada->id)->get()->map(fn ($r) => [$r->status, $r->due_on])->all(), 'parking never suspends or moves reviews');
        $this->assertSame($e->id, app(RoadmapService::class)->current($ada->id)->id);
        $this->assertSame($path['topics'], DB::table('topic_progress')->where('enrolment_id', $e->id)->count());

        $this->actingAs($ada);
        $this->getJson('/v1/today')->assertOk()->assertJsonPath('enrolment.roadmap_slug', $path['slug']);
        $list = collect($this->getJson('/v1/enrolments')->json('enrolments'));
        $this->assertSame([true, false], $list->pluck('made_for_you')->all(), 'the active path is listed first');
        $this->assertSame([false, true], [$list[0]['reviewed'], $list[1]['reviewed']], 'the made-for-you path is marked unreviewed; the authored one is reviewed');
        $this->assertSame(['active', 'paused'], $list->pluck('status')->all());

        // Switch back: the path is kept, not restarted.
        $this->postJson("/v1/enrolments/{$main->id}/focus")->assertOk()->assertJsonPath('enrolment.status', 'active');
        $this->assertSame(['paused', 'active'], DB::table('roadmap_enrolments')->whereIn('id', [$e->id, $main->id])->orderByRaw("id = '{$main->id}'")->pluck('status')->all());
        $this->assertSame(2, DB::table('roadmap_enrolments')->where('user_id', $ada->id)->count(), 'no new enrolment was made');
        $this->assertSame(1, DB::table('roadmap_enrolments')->where('user_id', $ada->id)->where('status', 'active')->count());

        // Starting the same path again just brings it back into focus.
        [$again, $createdAgain] = app(RoadmapService::class)->startPath($ada->id, (string) $ada->analytics_subject_id, $path['slug'], 'Europe/London');
        $this->assertFalse($createdAgain);
        $this->assertSame($e->id, $again->id);
        $this->assertSame(1, DB::table('roadmap_enrolments')->where('user_id', $ada->id)->where('status', 'active')->count());
    }

    public function test_an_open_session_is_set_aside_when_focus_moves_and_reviews_keep_working(): void
    {
        $this->publishContent();
        $ada = $this->newUser();
        $this->enrolMain($ada);
        $this->actingAs($ada);
        $sid = $this->postJson('/v1/sessions', ['mode' => 'practise'])->assertCreated()->json('session.id');
        $path = $this->makePathFor($ada);

        app(RoadmapService::class)->startPath($ada->id, (string) $ada->analytics_subject_id, $path['slug'], 'Europe/London');

        $this->assertSame('suspended', DB::table('learning_sessions')->where('id', $sid)->value('status'));
        $this->assertSame(0, DB::table('learning_sessions')->where('user_id', $ada->id)->where('status', 'open')->count());
    }

    public function test_starting_a_path_after_an_explicit_pause_brings_waiting_reviews_back_shifted_not_lost(): void
    {
        $this->publishContent();
        $ada = $this->newUser();
        $main = $this->enrolMain($ada);
        $this->claimSampleFor($ada);
        $due = DB::table('review_schedule')->where('user_id', $ada->id)->value('due_on');
        $this->actingAs($ada)->patchJson("/v1/enrolments/{$main->id}", ['status' => 'paused'])->assertOk();
        $this->assertSame('suspended', DB::table('review_schedule')->where('user_id', $ada->id)->value('status'));
        $path = $this->makePathFor($ada);

        $this->at('2026-10-16T19:30:00Z'); // four days away
        app(RoadmapService::class)->startPath($ada->id, (string) $ada->analytics_subject_id, $path['slug'], 'Europe/London');

        $row = DB::table('review_schedule')->where('user_id', $ada->id)->first();
        $this->assertSame('active', $row->status);
        $this->assertSame(Str::of($due)->toString() < $row->due_on, true, 'waiting reviews moved forward by the days away: no debt');
    }

    public function test_the_one_active_path_rule_is_enforced_by_the_database(): void
    {
        $this->publishContent();
        $ada = $this->newUser();
        $main = $this->enrolMain($ada);
        $path = $this->makePathFor($ada);
        app(RoadmapService::class)->startPath($ada->id, (string) $ada->analytics_subject_id, $path['slug'], 'Europe/London');

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::update("UPDATE roadmap_enrolments SET status = 'active' WHERE id = ?", [$main->id]);
    }
}
