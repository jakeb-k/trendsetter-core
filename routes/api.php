<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\GoalController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/ai-plan/chat', [GoalController::class, 'generatePlan'])->name('api.ai.plan.chat');

        Route::get('/events/{event}/feedback', [EventController::class, 'getEventFeedback'])->name('api.event.feedback');
        
        Route::get('goals/{goal}/feedback', [GoalController::class, 'getGoalEventFeedback'])->name('api.goal.feedback');

        Route::get('/goals', [GoalController::class, 'getGoals'])->name('api.goals.get');

        Route::post('/goals', [GoalController::class, 'storeGoal'])->name('api.goals.store');

        Route::get('/goals/completed', [GoalController::class, 'getCompletedGoals'])->name('api.goals.completed');

        Route::post('/goals/{goal}/complete', [GoalController::class, 'completeGoal'])->name('api.goals.complete');

        Route::post('/goals/{goal}/review', [GoalController::class, 'createGoalReview'])->name('api.goals.review.store');

        Route::get('/goals/{goal}/review', [GoalController::class, 'getGoalReview'])->name('api.goals.review.get');

        Route::post('/events', [EventController::class, 'storeEvent'])->name('api.events.store');

        Route::post('/events/{event}/feedback', [EventController::class, 'storeEventFeedback'])->name('api.event.feedback.store');

        Route::put('/events/{event}/feedback', [EventController::class, 'updateEventFeedback'])->name('api.event.feedback.update');

        Route::delete('/events/{event}/feedback', [EventController::class, 'deleteEventFeedback'])->name('api.event.feedback.delete');
    });
        Route::get('/goals', [GoalController::class, 'getGoals'])->name('api.goals.get');

    Route::post('/auth/login', [AuthenticatedSessionController::class, 'storeApi'])->name('api.login');
});
