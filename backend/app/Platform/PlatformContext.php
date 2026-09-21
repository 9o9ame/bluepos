<?php

namespace App\Platform;

use App\Models\Platform\PlatformDevice;
use App\Models\Platform\PlatformUser;
use RuntimeException;

class PlatformContext
{
    private ?PlatformUser $user = null;

    private ?PlatformDevice $device = null;

    public function clear(): void
    {
        $this->user = null;
        $this->device = null;
    }

    public function hydrate(PlatformUser $user, ?PlatformDevice $device = null): void
    {
        $this->user = $user;
        $this->device = $device;
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function user(): PlatformUser
    {
        return $this->user ?? throw new RuntimeException('Platform context is not set.');
    }

    public function device(): ?PlatformDevice
    {
        return $this->device;
    }

    public function can(string $permission): bool
    {
        return $this->hasUser() && $this->user()->canPlatform($permission);
    }
}
