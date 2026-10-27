<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

\Illuminate\Support\Facades\Schedule::call(function (): void {
    \Illuminate\Support\Facades\DB::table('login_location_observations')
        ->where('expires_at', '<=', now())->delete();
})->daily()->name('prune-expired-login-locations')->withoutOverlapping();

\Illuminate\Support\Facades\Schedule::call(function (): void {
    \Illuminate\Support\Facades\DB::table('device_access_requests')
        ->whereNotNull('request_location_expires_at')
        ->where('request_location_expires_at', '<=', now())
        ->update([
            'request_latitude_approx' => null,
            'request_longitude_approx' => null,
            'request_location_expires_at' => null,
        ]);
})->daily()->name('scrub-expired-device-request-locations')->withoutOverlapping();

/*
 * Privacy retention: redact network identifiers from old authentication events
 * while preserving the audit event, actor, ULIDs and timestamp.
 * This narrowly-scoped privacy exception intentionally uses a query update
 * rather than altering the general immutable AuditLog model.
 */
\Illuminate\Support\Facades\Schedule::call(function (): void {
    \Illuminate\Support\Facades\DB::table('audit_logs')
        ->whereIn('event', ['LOGIN_SUCCESS', 'LOGIN_FAILURE', 'LOGIN_BLOCKED', 'MFA_FAILURE', 'MFA_SUCCESS'])
        ->where('occurred_at', '<', now()->subDays((int) config('security.privacy.security_ip_retention_days', 90)))
        ->where(function ($q): void {
            $q->whereNotNull('ip_address')->orWhereNotNull('user_agent');
        })
        ->update(['ip_address' => null, 'user_agent' => null]);
})->daily()->name('redact-expired-security-network-identifiers')->withoutOverlapping();
