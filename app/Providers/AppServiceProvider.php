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
