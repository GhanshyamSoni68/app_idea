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
        // Rate limits (docs/09 §3.4). Login has no hard lockout, which would let an attacker lock learners out.
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinutes(15, 5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perHour(50)->by($r->ip()),
        ]);
        RateLimiter::for('recovery', fn (Request $r) => [Limit::perHour(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()), Limit::perHour(20)->by($r->ip())]);
        RateLimiter::for('sign-up', fn (Request $r) => [Limit::perHour(10)->by($r->ip())]);
        RateLimiter::for('guest', fn (Request $r) => [Limit::perMinute(60)->by($r->ip())]);
        RateLimiter::for('explore', fn (Request $r) => [Limit::perHour(10)->by($r->user()?->id ?: $r->ip())]);
        RateLimiter::for('api', fn (Request $r) => [Limit::perMinute(240)->by($r->user()?->id ?: $r->ip())]);
    }
}
