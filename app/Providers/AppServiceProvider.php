<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('partner-invite-authenticated', function (Request $request) {
            $userKey = (string) ($request->user()?->getAuthIdentifier() ?? 'unknown-user');
            $ipKey = (string) ($request->ip() ?? 'unknown-ip');

            return [
                Limit::perMinute((int) config('services.partner_invites.auth_rate_limit_per_minute', 12))
                    ->by('partner-invite-auth-user-minute:'.$userKey),
                Limit::perHour((int) config('services.partner_invites.auth_rate_limit_per_hour', 120))
                    ->by('partner-invite-auth-user-hour:'.$userKey),
                Limit::perMinute((int) config('services.partner_invites.auth_ip_rate_limit_per_minute', 30))
                    ->by('partner-invite-auth-ip:'.$ipKey),
            ];
        });

        RateLimiter::for('partner-invite-public', function (Request $request) {
            $ipKey = (string) ($request->ip() ?? 'unknown-ip');
            $plainToken = (string) ($request->query('token') ?? $request->input('token') ?? '');
            $tokenKey = $plainToken !== '' ? hash('sha256', $plainToken) : 'no-token';

            return [
                Limit::perMinute(30)->by('partner-invite-ip:'.$ipKey),
                Limit::perMinute(10)->by('partner-invite-token:'.$tokenKey),
            ];
        });
    }
}
