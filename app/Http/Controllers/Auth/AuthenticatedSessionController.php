<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\PartnerInviteRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Show the login page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     * 
     * This is only altered for extension -> web app
     */
    public function store(LoginRequest $request, PartnerInviteRegistrationService $partnerInviteRegistrationService): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();
        $partnerInviteRegistrationService->claimAcceptedInvitesForUser($request->user());

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Handle an incoming API authentication request.
     *
     * @param LoginRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeApi(LoginRequest $request, PartnerInviteRegistrationService $partnerInviteRegistrationService)
    {
        \Log::info('API Login Attempt');
        $request->authenticate();

        $user = $request->user();
        $partnerInviteRegistrationService->claimAcceptedInvitesForUser($user);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'goals' => $user->goals()->with(['events', 'events.feedback'])->get(), 
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
