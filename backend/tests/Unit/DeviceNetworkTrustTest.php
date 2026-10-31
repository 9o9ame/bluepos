<?php

namespace Tests\Unit;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Security\DeviceNetworkTrust;
use Illuminate\Http\Request;
use Tests\TestCase;

class DeviceNetworkTrustTest extends TestCase
{
    public function test_owner_trust_requires_active_device_and_unexpired_window(): void
    {
        $device = new Device();
        $device->status = DeviceStatus::Active;
        $device->trusted_until = now()->addMinute();

        $this->assertTrue($device->isTrusted());

        $device->trusted_until = now()->subSecond();
        $this->assertFalse($device->isTrusted());

        $device->trusted_until = now()->addMonth();
        $device->status = DeviceStatus::Revoked;
        $this->assertFalse($device->isTrusted());
    }

    public function test_changed_public_ip_requires_reverification_for_bound_device(): void
    {
        $trust = app(DeviceNetworkTrust::class);
        $initial = Request::create('/api/auth/login', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']);
        $same = Request::create('/api/auth/login', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']);
        $changed = Request::create('/api/auth/login', 'POST', [], [], [], ['REMOTE_ADDR' => '198.51.100.20']);

        $device = new Device();
        $device->trusted_ip_hash = $trust->fingerprint($initial);

        $this->assertNotNull($device->trusted_ip_hash);
        $this->assertFalse($trust->requiresReverification($device, $same));
        $this->assertTrue($trust->requiresReverification($device, $changed));
    }

    public function test_legacy_device_without_network_binding_remains_grandfathered(): void
    {
        $device = new Device();
        $device->trusted_ip_hash = null;

        $request = Request::create('/api/auth/login', 'POST', [], [], [], ['REMOTE_ADDR' => '198.51.100.20']);

        $this->assertFalse(app(DeviceNetworkTrust::class)->requiresReverification($device, $request));
    }
}
