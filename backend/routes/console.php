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
