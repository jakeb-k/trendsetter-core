<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\GoalController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


Route::prefix('v1')->group(function () {
    Route::middleware('auth:sanctum')->group(function() {
        Route::post('/ai-plan/chat', [GoalController::class, 'generatePlan'])->name('api.ai.plan.chat'); 
    }); 
    Route::post('/auth/login', [AuthenticatedSessionController::class, 'storeApi'])->name('api.login'); 
});
//I want to get better at programming and become a senior level developer in 2 years