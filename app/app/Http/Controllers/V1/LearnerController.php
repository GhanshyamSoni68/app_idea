<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\EventRecorder;
use App\Modules\Assessment\EvidenceService;
use App\Modules\Learning\ChallengeService;
use App\Modules\Roadmap\RoadmapService;
use App\Modules\Scheduling\TodayService;
use App\Support\ApiException;
use App\Support\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Today, evidence, enrolments and the roadmap catalogue. */
class LearnerController extends Controller
{
    public function __construct(private TodayService $today, private EvidenceService $evidence, private RoadmapService $roadmap, private ChallengeService $challenges) {}

    public function today(Request $request): JsonResponse
    {
        $data = $request->validate(['mode' => ['nullable', Rule::in(['small', 'practise', 'build'])]]);

        return response()->json($this->today->recommend(Learner::of($request->user()), $data['mode'] ?? null));
    }

    public function evidence(Request $request): JsonResponse
    {
        return response()->json(['evidence' => $this->evidence->view((string) $request->user()->id)]);
    }

    public function enrolments(Request $request): JsonResponse
    {
        $rows = DB::select('SELECT * FROM roadmap_enrolments WHERE user_id = ? ORDER BY enrolled_at DESC', [$request->user()->id]);

        return response()->json(['enrolments' => array_map(fn ($e) => [
            'id' => $e->id, 'status' => $e->status, 'enrolled_at' => $e->enrolled_at, 'completed_at' => $e->completed_at, 'progress' => $this->roadmap->progress($e),
        ], $rows)]);
    }

    public function enrol(Request $request): JsonResponse
    {
        $user = $request->user();
        [$enrolment, $created] = $this->roadmap->enrol((string) $user->id, (string) $user->analytics_subject_id);

        return response()->json($this->roadmap->view($enrolment), $created ? 201 : 200);
    }

    public function enrolment(Request $request, string $id): JsonResponse
    {
        $enrolment = $this->roadmap->find((string) $request->user()->id, $id);
        $view = $this->roadmap->view($enrolment);
        $learner = Learner::of($request->user());
        // Whether "I already know this" is still offered for each topic (CH-1, CH-5).
        foreach ($view['modules'] as &$module) {
            foreach ($module['topics'] as &$t) {
                $row = DB::selectOne('SELECT t.*, tp.state FROM roadmap_topics t JOIN topic_progress tp ON tp.topic_id = t.id AND tp.enrolment_id = ? WHERE t.id = ?', [$enrolment->id, $t['id']]);
                $t['challenge_available'] = $enrolment->status === 'active' && $this->challenges->availability($learner, $enrolment, $row);
            }
        }
        unset($module, $t);

        return response()->json($view);
    }

    /** Pause or resume (R05). Pausing also pauses reviews; resuming shifts them, so nothing piles up. */
    public function patchEnrolment(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'paused'])]]);
        $user = $request->user();
        $learner = Learner::of($user);
        $enrolment = $this->roadmap->find($learner->id, $id);
        $updated = $this->roadmap->setPaused($enrolment, $data['status'] === 'paused', $learner->subjectId, $learner->timeZone);

        return response()->json(['enrolment' => ['id' => $updated->id, 'status' => $updated->status, 'progress' => $this->roadmap->progress($updated)]]);
    }

    public function defer(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $row = $this->roadmap->defer((string) $user->id, (string) $user->analytics_subject_id, $id);

        return response()->json(['topic' => ['id' => $id, 'state' => $row->state, 'credit' => false]]);
    }

    public function bringBack(Request $request, string $id): JsonResponse
    {
        $row = $this->roadmap->bringBack((string) $request->user()->id, $id);

        return response()->json(['topic' => ['id' => $id, 'state' => $row->state]]);
    }

    public function challenge(Request $request, string $id): JsonResponse
    {
        return response()->json($this->challenges->start(Learner::of($request->user()), $id), 201);
    }

    /** Public roadmap catalogue (docs/02): outcome, required topics, effort, and the coverage note (R01). */
    public function roadmaps(): JsonResponse
    {
        $rows = DB::select(
            "SELECT r.slug, rv.title, rv.outcome, rv.estimated_effort_text, rv.required_topic_count, rv.version
             FROM roadmaps r JOIN roadmap_versions rv ON rv.roadmap_id = r.id AND rv.status = 'published'
             WHERE rv.version = (SELECT max(version) FROM roadmap_versions WHERE roadmap_id = r.id AND status = 'published')",
        );

        return response()->json(['roadmaps' => $rows])->header('Cache-Control', 'public, max-age=300');
    }

    public function roadmapDetail(string $slug): JsonResponse
    {
        $v = DB::selectOne(
            "SELECT rv.* FROM roadmap_versions rv JOIN roadmaps r ON r.id = rv.roadmap_id WHERE r.slug = ? AND rv.status = 'published' ORDER BY rv.version DESC LIMIT 1",
            [$slug],
        );
        if (! $v) {
            throw ApiException::notFound('That roadmap');
        }
        $modules = [];
        foreach (DB::select('SELECT * FROM roadmap_modules WHERE roadmap_version_id = ? ORDER BY position', [$v->id]) as $m) {
            $topics = DB::select('SELECT topic_key, title, kind, estimated_minutes FROM roadmap_topics WHERE module_id = ? ORDER BY position', [$m->id]);
            $modules[] = ['module_key' => $m->module_key, 'title' => $m->title, 'topics' => $topics];
        }

        return response()->json(['roadmap' => [
            'slug' => $slug, 'version' => (int) $v->version, 'title' => $v->title, 'outcome' => $v->outcome,
            'estimated_effort' => $v->estimated_effort_text, 'required_topic_count' => (int) $v->required_topic_count, 'coverage_note' => $v->coverage_note, 'modules' => $modules,
        ]])->header('Cache-Control', 'public, max-age=300');
    }

    /** Batch of allow-listed client events. Signed in: stamped with the account's subject_id (docs/10 §3.4). */
    public function events(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject_id' => ['nullable', 'uuid'],
            'device_class' => ['nullable', Rule::in(['phone', 'tablet', 'desktop', 'unknown'])],
            'events' => ['required', 'array', 'max:50'],
            'events.*.client_event_id' => ['required', 'uuid'],
            'events.*.name' => ['required', 'string', 'max:64'],
            'events.*.properties' => ['nullable', 'array'],
            'events.*.mode' => ['nullable', Rule::in(['small', 'practise', 'build'])],
        ]);
        $user = $request->user();
        $guest = $user === null;
        $subject = $guest ? ($data['subject_id'] ?? null) : (string) $user->analytics_subject_id;
        if (! $subject) {
            throw new ApiException('subject_required', 'A guest needs a subject_id to send events.', 422);
        }
        $accepted = 0;
        foreach ($data['events'] as $e) {
            $ok = EventRecorder::client($e['name'], $subject, $e['properties'] ?? [], $e['client_event_id'], $guest, [
                'device_class' => $data['device_class'] ?? 'unknown', 'mode' => $e['mode'] ?? null,
            ]);
            $accepted += $ok ? 1 : 0;
        }

        return response()->json(['accepted' => $accepted, 'rejected' => count($data['events']) - $accepted]);
    }
}
