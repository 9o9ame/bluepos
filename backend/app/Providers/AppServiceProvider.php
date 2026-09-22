<?php

namespace App\Providers;

use App\Authz\PermissionService;
use App\Platform\PlatformContext;
use App\Security\SecurityOtp;
use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(PermissionService::class);
        $this->app->scoped(PlatformContext::class);
    }

    public function boot(): void
    {
        JsonResource::withoutWrapping();

        RateLimiter::for('login', function (Request $request) {
            $identity = strtolower((string) $request->input('tenant_code')).'|'.strtolower((string) $request->input('username'));

            return Limit::perMinute(SecurityOtp::rateLimitPerMinute())->by($request->ip().'|'.$identity);
        });

        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(SecurityOtp::rateLimitPerMinute())->by((string) $request->ip());
        });

        RateLimiter::for('password-reset', function (Request $request) {
            $identity = strtolower((string) $request->input('tenant_code')).'|'.strtolower((string) $request->input('username'));

            return Limit::perMinute(SecurityOtp::rateLimitPerMinute())->by($request->ip().'|'.$identity);
        });

        RateLimiter::for('mfa', function (Request $request) {
            return Limit::perMinute(SecurityOtp::rateLimitPerMinute())->by($request->ip().'|'.(string) $request->input('challenge_ulid'));
        });

        RateLimiter::for('devices', function (Request $request) {
            return Limit::perMinute(10)->by((string) $request->ip());
        });

        RateLimiter::for('auth', function (Request $request) {
            $id = optional($request->user())->getAuthIdentifier() ?: $request->ip();

            return Limit::perMinute(60)->by((string) $id);
        });

        RateLimiter::for('platform-login', function (Request $request) {
            return Limit::perMinute(SecurityOtp::rateLimitPerMinute())->by($request->ip().'|'.strtolower((string) $request->input('email')));
        });

        RateLimiter::for('platform-mfa', function (Request $request) {
            return Limit::perMinute(SecurityOtp::rateLimitPerMinute())->by($request->ip().'|'.(string) $request->input('challenge_ulid'));
        });

        RateLimiter::for('platform-password-reset', function (Request $request) {
            return Limit::perMinute(SecurityOtp::rateLimitPerMinute())->by($request->ip().'|'.strtolower((string) $request->input('email')));
        });

        RateLimiter::for('platform-auth', function (Request $request) {
            $id = optional($request->user('platform'))->getAuthIdentifier() ?: $request->ip();

            return Limit::perMinute(60)->by((string) $id);
        });
    }
}
