<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Modules\Generation\ExploreService;
use App\Modules\Generation\GenerationException;
use App\Modules\Roadmap\RoadmapService;
use App\Support\ApiException;
use App\Support\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** "Learn something else" (docs/16 §8). The topic is learner-typed text: validated here, guarded in the service, never an analytics property. */
class ExploreController extends Controller
{
    public function __construct(private ExploreService $explore, private RoadmapService $roadmaps) {}

    public function index(Request $request): JsonResponse
    {
        if (! ExploreService::enabled()) {
            throw new ApiException('explore_unavailable', 'Making a path on your own topic is not available yet.', 404);
        }

        return response()->json(['requests' => $this->explore->recent(Learner::of($request->user()))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'topic' => ['required', 'string', 'max:300'],
            'level' => ['sometimes', Rule::in(array_keys(ExploreService::LEVELS))],
            // AI processing is opt-in after disclosure (docs/09 §3.7, C59): the learner ticks a box saying they understand.
            'ack' => ['required', 'accepted'],
        ], ['ack.required' => 'Please tick the box to say you understand.', 'ack.accepted' => 'Please tick the box to say you understand.']);
        try {
            $id = $this->explore->request(Learner::of($request->user()), $data['topic'], $data['level'] ?? 'some', (string) config('generation.consent_version'));
        } catch (GenerationException $e) {
            throw match (true) {
                $e->code_ === 'topic_rejected' => new ApiException('topic_rejected', $e->getMessage(), 422, false, null, [['field' => 'topic', 'code' => 'topic_rejected', 'message' => $e->getMessage()]]),
                str_starts_with($e->code_, 'budget') => new ApiException('explore_limit', $e->getMessage(), 429, true),
                default => new ApiException($e->code_, $e->getMessage(), 503, true),
            };
        }

        return response()->json(['id' => $id, 'status' => 'working', 'stage' => 'queued'], 202);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->explore->status(Learner::of($request->user()), $id))->header('Cache-Control', 'no-store');
    }

    public function start(Request $request, string $id): JsonResponse
    {
        [$enrolment, $created] = $this->explore->start(Learner::of($request->user()), $id);

        return response()->json($this->roadmaps->view($enrolment) + ['created' => $created], $created ? 201 : 200);
    }
}
