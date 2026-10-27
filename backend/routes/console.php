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

// Redact old network identifiers without deleting immutable security events.
\Illuminate\Support\Facades\Schedule::call(fn () => app(\App\Security\SecurityPrivacyRetention::class)
    ->redactExpiredNetworkIdentifiers())
    ->daily()->name('redact-expired-security-network-identifiers')->withoutOverlapping();
