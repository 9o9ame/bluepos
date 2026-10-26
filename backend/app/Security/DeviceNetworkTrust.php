<?php

namespace App\Security;

use App\Models\Device;
use Illuminate\Http\Request;

final class DeviceNetworkTrust
{
    public function fingerprint(Request $request): ?string
    {
        $ip = $request->ip();
        if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    public function requiresReverification(Device $device, Request $request): bool
    {
        // Pre-existing trusted devices are not retroactively blocked by this additive migration.
        if ($device->trusted_ip_hash === null) {
            return false;
        }

        $fingerprint = $this->fingerprint($request);

        return $fingerprint === null || ! hash_equals($device->trusted_ip_hash, $fingerprint);
    }
}
