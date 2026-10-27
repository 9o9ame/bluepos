<?php

namespace App\Security;

use App\Auth\AuthenticatedSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LoginLocationService
{
    public function record(Request $request, AuthenticatedSession $session): void
    {
        if (! config('security.tenant_login_location_required')) {
            return;
        }
        $latitude = (float) $request->input('location_latitude');
        $longitude = (float) $request->input('location_longitude');
        $accuracy = (int) ceil((float) $request->input('location_accuracy'));

        DB::table('login_location_observations')->insert([
            'ulid' => (string) Str::ulid(),
            'tenant_id' => $session->tenant->id,
            'membership_id' => $session->membership->id,
            'device_id' => $session->device?->id,
            'latitude_approx' => round($latitude, 2),
            'longitude_approx' => round($longitude, 2),
            'accuracy_meters' => min(100000, max(0, $accuracy)),
            'observed_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);
    }
}
