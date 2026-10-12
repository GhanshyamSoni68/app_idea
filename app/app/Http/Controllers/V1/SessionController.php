<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Modules\Learning\AssistanceService;
use App\Modules\Learning\AttemptService;
use App\Modules\Learning\CompletionService;
use App\Modules\Learning\DraftService;
use App\Modules\Learning\SessionService;
use App\Support\ApiException;
use App\Support\Idempotency;
use App\Support\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Ramsey\Uuid\Uuid;

class SessionController extends Controller
{
    public function __construct(
        private SessionService $sessions,
        private DraftService $drafts,
        private AssistanceService $assistance,
        private AttemptService $attempts,
        private CompletionService $completion,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['nullable', Rule::in(['small', 'practise', 'build'])],
            'session_id' => ['nullable', 'uuid'],
            'if_open' => ['nullable', Rule::in(['resume', 'suspend'])],
            'recommendation_id' => ['nullable', 'string', 'max:40'],
            'origin' => ['nullable', Rule::in(['today', 'smaller_mode', 'return_screen', 'roadmap', 'reminder_link'])],
        ]);
        [$payload, $created] = $this->sessions->start(Learner::of($request->user()), $data);
        $payload['resumed'] = ! $created;

        return response()->json($payload, $created ? 201 : 200);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->sessions->payload(Learner::of($request->user()), $id));
    }

    public function draft(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'base_revision' => ['required', 'integer', 'min:0'],
            'save_id' => ['required', 'uuid'],
            'step_index' => ['required', 'integer', 'min:0', 'max:50'],
            'data' => ['present', 'array'],
            'device_label' => ['nullable', 'string', 'max:60'],
        ]);

        return response()->json($this->drafts->save(Learner::of($request->user()), $id, $data));
    }

    public function hint(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['step_index' => ['required', 'integer', 'min:0', 'max:50'], 'hint_number' => ['required', 'integer', 'min:1', 'max:10']]);

        return response()->json($this->assistance->hint(Learner::of($request->user()), $id, $data['step_index'], $data['hint_number']));
    }

    public function reveal(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['step_index' => ['required', 'integer', 'min:0', 'max:50'], 'confirm' => ['required', 'accepted']]);

        return response()->json($this->assistance->reveal(Learner::of($request->user()), $id, $data['step_index']));
    }

    public function attempt(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'step_index' => ['required', 'integer', 'min:0', 'max:50'],
            'assessment_item_id' => ['required', 'uuid'],
            'content_version_id' => ['required', 'uuid'],
            'answer' => ['required', 'array'],
            'retry' => ['nullable', 'boolean'],
        ]);
        $key = (string) $request->header('Idempotency-Key', '');
        if (! Uuid::isValid($key)) {
            throw new ApiException('idempotency_key_required', 'An attempt needs an Idempotency-Key header that is a UUID.', 400);
        }
        $learner = Learner::of($request->user());

        return Idempotency::run($request, $learner->id, 'attempt_submit', $data + ['_session' => $id], fn () => $this->attempts->submit($learner, $id, strtolower($key), $data));
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['base_revision' => ['required', 'integer', 'min:0']]);
        $learner = Learner::of($request->user());

        return Idempotency::run($request, $learner->id, 'session_complete', $data + ['_session' => $id], fn () => $this->completion->complete($learner, $id, (int) $data['base_revision']));
    }
}
