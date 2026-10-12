<?php

namespace App\Modules\Profile\Http;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\EventRecorder;
use App\Modules\Profile\ProfileService;
use App\Support\LearnerDay;
use App\Support\Clock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function __construct(private ProfileService $profile) {}

    public function show(Request $request): JsonResponse
    {
        $uid = (string) $request->user()->id;

        return response()->json(['preferences' => $this->profile->preferences($uid), 'goal' => $this->profile->goal($uid)]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'time_zone' => ['required', 'timezone:all'],
            'available_days' => ['required', 'array', 'max:7'],
            'available_days.*' => ['integer', 'between:1,7'],
            'preferred_mode' => ['required', Rule::in(ProfileService::MODES)],
            'weekly_session_target' => ['required', 'integer', 'between:1,7'],
            'stack_tags' => ['array', 'max:5'],
            'stack_tags.*' => [Rule::in(ProfileService::STACKS)],
            'diagnostic_status' => [Rule::in(['not_offered', 'skipped'])],
        ]);
        $user = $request->user();
        $first = $this->profile->savePreferences((string) $user->id, $data);
        if ($first) {
            EventRecorder::server('onboarding_completed', $user->analytics_subject_id, [
                'diagnostic_status' => $data['diagnostic_status'] ?? 'skipped',
                'diagnostic_items_answered' => 0,
                'planned_days_per_week' => count($data['available_days']),
                'preferred_mode' => $data['preferred_mode'],
                'reminder_opted_in' => false,
                'goal_key' => $this->profile->goal((string) $user->id)['outcome_key'] ?? 'general',
            ], ['local_date' => LearnerDay::of(Clock::now(), $data['time_zone']), 'mode' => $data['preferred_mode']]);
        }

        return response()->json(['preferences' => $this->profile->preferences((string) $user->id)]);
    }

    public function goal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'outcome_key' => ['required', Rule::in(ProfileService::GOALS)],
            'note' => ['nullable', 'string', 'max:280'],
        ]);
        $this->profile->setGoal((string) $request->user()->id, $data['outcome_key'], $data['note'] ?? null);

        return response()->json(['goal' => $this->profile->goal((string) $request->user()->id)]);
    }
}
