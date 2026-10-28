<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final class DeviceAccessRequested implements ShouldBroadcastNow
{
    public function __construct(
        public readonly string $tenantUlid,
        public readonly string $recipientMembershipUlid,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('bluepos.tenant.'.$this->tenantUlid.'.member.'.$this->recipientMembershipUlid);
    }

    public function broadcastAs(): string
    {
        return 'device.access.requested';
    }

    public function broadcastWith(): array
    {
        // Notify clients to refresh securely-scoped APIs. Never expose request IP, PII,
        // database IDs or secret device details through the third-party broadcaster.
        return ['type' => 'device_access_requested'];
    }
}
