<?php

use App\Http\Controllers\V1\GuestController;
use App\Http\Controllers\V1\LearnerController;
use App\Http\Controllers\V1\SessionController;
use App\Modules\Identity\Http\AuthController;
use App\Modules\Profile\Http\ProfileController;
use Illuminate\Support\Facades\Route;

// The /v1 API (docs/02 §5.2). Same origin as the SPA; session cookie plus CSRF token on unsafe methods.
Route::prefix('v1')->group(function () {
    // Public
    Route::post('auth/sign-up', [AuthController::class, 'signUp'])->middleware('throttle:sign-up');
    Route::post('auth/sign-in', [AuthController::class, 'signIn'])->middleware('throttle:login');
    Route::post('auth/sign-out', [AuthController::class, 'signOut']);

    Route::get('roadmaps', [LearnerController::class, 'roadmaps']);
    Route::get('roadmaps/{slug}', [LearnerController::class, 'roadmapDetail']);

    Route::middleware('throttle:guest')->group(function () {
        Route::get('guest/sample', [GuestController::class, 'sample']);
        Route::post('guest/attempts', [GuestController::class, 'attempt']);
        Route::post('guest/hints', [GuestController::class, 'hint']);
        Route::post('guest/reveal', [GuestController::class, 'reveal']);
        Route::post('events', [LearnerController::class, 'events']);
    });

    // Signed-in learner: every record is scoped to the owner taken from the session.
    Route::middleware(['auth', 'throttle:api'])->group(function () {
        Route::get('me', [AuthController::class, 'show']);
        Route::get('me/preferences', [ProfileController::class, 'show']);
        Route::put('me/preferences', [ProfileController::class, 'update']);
        Route::put('me/goal', [ProfileController::class, 'goal']);

        Route::post('guest/claim', [GuestController::class, 'claim']);

        Route::get('today', [LearnerController::class, 'today']);
        Route::post('sessions', [SessionController::class, 'store']);
        Route::get('sessions/{id}', [SessionController::class, 'show'])->whereUuid('id');
        Route::put('sessions/{id}/draft', [SessionController::class, 'draft'])->whereUuid('id');
        Route::post('sessions/{id}/hints', [SessionController::class, 'hint'])->whereUuid('id');
        Route::post('sessions/{id}/reveal', [SessionController::class, 'reveal'])->whereUuid('id');
        Route::post('sessions/{id}/attempts', [SessionController::class, 'attempt'])->whereUuid('id');
        Route::post('sessions/{id}/complete', [SessionController::class, 'complete'])->whereUuid('id');

        Route::get('enrolments', [LearnerController::class, 'enrolments']);
        Route::post('enrolments', [LearnerController::class, 'enrol']);
        Route::get('enrolments/{id}', [LearnerController::class, 'enrolment'])->whereUuid('id');
        Route::patch('enrolments/{id}', [LearnerController::class, 'patchEnrolment'])->whereUuid('id');
        Route::post('topics/{id}/defer', [LearnerController::class, 'defer'])->whereUuid('id');
        Route::delete('topics/{id}/defer', [LearnerController::class, 'bringBack'])->whereUuid('id');
        Route::post('topics/{id}/challenge', [LearnerController::class, 'challenge'])->whereUuid('id');
        Route::get('evidence', [LearnerController::class, 'evidence']);
    });
});
