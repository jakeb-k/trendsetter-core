<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\GoalPartnershipController;
use App\Http\Controllers\PartnerInviteController;
use App\Http\Controllers\PartnerNotificationController;
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

        Route::get('/goals/{goal}/partner-invites', [PartnerInviteController::class, 'listGoalPartnerInvites'])->name('api.goals.partner_invites.get');

        Route::post('/goals/{goal}/partner-invites', [PartnerInviteController::class, 'createGoalPartnerInvite'])
            ->middleware('throttle:partner-invite-authenticated')
            ->name('api.goals.partner_invites.store');

        Route::post('/partner-invites/{invite}/resend', [PartnerInviteController::class, 'resendGoalPartnerInviteEmail'])
            ->middleware('throttle:partner-invite-authenticated')
            ->name('api.partner_invites.resend');

        Route::delete('/partner-invites/{invite}', [PartnerInviteController::class, 'cancelGoalPartnerInvite'])->name('api.partner_invites.cancel');

        Route::get('/partnerships', [GoalPartnershipController::class, 'listGoalPartnerships'])->name('api.partnerships.get');

        Route::get('/partnerships/{partnership}/snapshot', [GoalPartnershipController::class, 'showGoalPartnershipSnapshot'])->name('api.partnerships.snapshot');

        Route::patch('/partnerships/{partnership}', [GoalPartnershipController::class, 'updateGoalPartnership'])->name('api.partnerships.update');

        Route::post('/partnerships/{partnership}/pause', [GoalPartnershipController::class, 'pauseGoalPartnershipAlerts'])->name('api.partnerships.pause');

        Route::post('/partnerships/{partnership}/unpause', [GoalPartnershipController::class, 'unpauseGoalPartnershipAlerts'])->name('api.partnerships.unpause');

        Route::delete('/partnerships/{partnership}', [GoalPartnershipController::class, 'unlinkGoalPartnership'])->name('api.partnerships.unlink');

        Route::get('/partner-notifications', [PartnerNotificationController::class, 'index'])->name('api.partner_notifications.get');

        Route::get('/partner-notifications/unread-count', [PartnerNotificationController::class, 'unreadCount'])->name('api.partner_notifications.unread_count');

        Route::post('/partner-notifications/{notificationId}/read', [PartnerNotificationController::class, 'markRead'])->name('api.partner_notifications.read');

        Route::post('/partner-notifications/read-all', [PartnerNotificationController::class, 'markAllRead'])->name('api.partner_notifications.read_all');

        Route::delete('/partner-notifications/clear-read', [PartnerNotificationController::class, 'clearRead'])->name('api.partner_notifications.clear_read');

        Route::post('/partner-notifications/{notificationId}/encouragement', [PartnerNotificationController::class, 'sendEncouragement'])->name('api.partner_notifications.encouragement');

        Route::post('/events', [EventController::class, 'storeEvent'])->name('api.events.store');

        Route::post('/events/{event}/feedback', [EventController::class, 'storeEventFeedback'])->name('api.event.feedback.store');
    });

    Route::get('/partner-invites/resolve', [PartnerInviteController::class, 'resolveGoalPartnerInviteToken'])
        ->middleware('throttle:partner-invite-public')
        ->name('api.partner_invites.resolve');

    Route::post('/partner-invites/respond', [PartnerInviteController::class, 'respondGoalPartnerInvite'])
        ->middleware('throttle:partner-invite-public')
        ->name('api.partner_invites.respond');

    Route::get('/goals', [GoalController::class, 'getGoals'])->name('api.goals.get');

    Route::post('/auth/login', [AuthenticatedSessionController::class, 'storeApi'])->name('api.login');
});
