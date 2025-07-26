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
        Route::post('/events/feedback', [EventController::class, 'storeEventFeedback'])->name('api.event.feedback.store');
        Route::put('/events/{event}/feedback', [EventController::class, 'updateEventFeedback'])->name('api.event.feedback.update');
        Route::delete('/events/{event}/feedback', [EventController::class, 'deleteEventFeedback'])->name('api.event.feedback.delete');
    });
    Route::post('/auth/login', [AuthenticatedSessionController::class, 'storeApi'])->name('api.login');
});
//I want to get better at programming and become a senior level developer in 2 years
