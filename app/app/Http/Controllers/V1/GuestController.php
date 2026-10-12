<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Modules\Assessment\Evaluator;
use App\Modules\Catalogue\ContentRepository;
use App\Modules\Catalogue\LearnerProjection;
use App\Modules\Identity\ClaimService;
use App\Support\ApiException;
use App\Support\Idempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Guest endpoints (docs/02): first value before an account. Nothing here stores anything. */
class GuestController extends Controller
{
    public function __construct(private ContentRepository $content, private Evaluator $evaluator, private ClaimService $claims) {}

    public function sample(): JsonResponse
    {
        $mission = $this->content->latest('mission', config('devstep.sample_mission'));
        if (! $mission) {
            throw new ApiException('sample_unavailable', 'The sample scenario is not available yet.', 503, true);
        }
        $practise = array_values(array_map(fn ($s) => $s['key'], array_filter($mission->body['front']['steps'], fn ($s) => in_array('practise', $s['modes'] ?? ['practise'], true))));

        return response()->json(['sample' => LearnerProjection::mission($mission, $this->content->missionItems($mission), $practise)])
            ->header('Cache-Control', 'public, max-age=300');
    }

    /** Stateless: evaluates one sample answer, returns authored feedback, writes nothing (docs/02 §6.8). */
    public function attempt(Request $request): JsonResponse
    {
        $data = $request->validate(['assessment_item_id' => ['required', 'uuid'], 'content_version_id' => ['required', 'uuid'], 'answer' => ['required', 'array']]);
        [$item, $mission] = $this->sampleItem($data['assessment_item_id'], $data['content_version_id']);
        $eval = $this->evaluator->evaluate($item->body, $data['answer'], LearnerProjection::misconceptions($mission));

        return response()->json(['outcome' => $eval['outcome'], 'evidence_basis' => $eval['basis'], 'feedback' => $eval['feedback']]);
    }

    /** Hints for the guest sample are stateless; the browser records how many were used in its own bundle. */
    public function hint(Request $request): JsonResponse
    {
        $data = $request->validate(['assessment_item_id' => ['required', 'uuid'], 'content_version_id' => ['required', 'uuid'], 'hint_number' => ['required', 'integer', 'min:1', 'max:10']]);
        [$item] = $this->sampleItem($data['assessment_item_id'], $data['content_version_id']);
        $hints = $item->body['hints'] ?? [];
        if ($data['hint_number'] > count($hints)) {
            throw new ApiException('hint_unavailable', 'There is no such hint.', 404);
        }

        return response()->json(['hint_number' => $data['hint_number'], 'text' => $hints[$data['hint_number'] - 1], 'remaining' => count($hints) - $data['hint_number']]);
    }

    public function reveal(Request $request): JsonResponse
    {
        $data = $request->validate(['assessment_item_id' => ['required', 'uuid'], 'content_version_id' => ['required', 'uuid']]);
        [$item] = $this->sampleItem($data['assessment_item_id'], $data['content_version_id']);

        return response()->json(['text' => $item->body['reveal']]);
    }

    public function claim(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'subject_id' => ['nullable', 'uuid'],
            'onboarding' => ['nullable', 'array'],
            'onboarding.time_zone' => ['nullable', 'timezone:all'],
            'onboarding.available_days' => ['nullable', 'array', 'max:7'],
            'onboarding.available_days.*' => ['integer', 'between:1,7'],
            'onboarding.preferred_mode' => ['nullable', Rule::in(['small', 'practise', 'build'])],
            'onboarding.weekly_session_target' => ['nullable', 'integer', 'between:1,7'],
            'onboarding.stack_tags' => ['nullable', 'array', 'max:5'],
            'onboarding.stack_tags.*' => ['string', 'max:20'],
            'onboarding.goal.outcome_key' => ['nullable', 'string', 'max:30'],
            'onboarding.goal.note' => ['nullable', 'string', 'max:280'],
            'sample' => ['required', 'array'],
            'sample.content_version_id' => ['required', 'uuid'],
            'sample.attempts' => ['present', 'array', 'max:'.config('devstep.guest_bundle_max_attempts')],
            'sample.attempts.*.assessment_item_id' => ['required', 'uuid'],
            'sample.attempts.*.answer' => ['required', 'array'],
            'sample.attempts.*.answered_at' => ['nullable', 'date'],
            'sample.attempts.*.client_attempt_id' => ['nullable', 'uuid'],
            'sample.attempts.*.hints_used' => ['nullable', 'integer', 'between:0,10'],
            'sample.attempts.*.revealed' => ['nullable', 'boolean'],
            'sample.attempts.*.feedback_viewed' => ['nullable', 'boolean'],
        ]);

        return Idempotency::run($request, (string) $user->id, 'guest_claim', $data, fn () => $this->claims->claim($user, $data));
    }

    /** @return array{0: object, 1: object} only primary items of the published sample mission are served to guests */
    private function sampleItem(string $itemId, string $missionCv): array
    {
        $mission = $this->content->version($missionCv);
        if (! $mission || $mission->content_key !== config('devstep.sample_mission')) {
            throw ApiException::notFound('That question');
        }
        $item = collect($this->content->missionItems($mission))->firstWhere('id', $itemId);
        if (! $item || $item->role !== 'primary') {
            throw ApiException::notFound('That question');
        }

        return [$item, $mission];
    }
}
