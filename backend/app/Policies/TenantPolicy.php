<?php

namespace App\Policies;

use App\Models\Platform\PlatformUser;
use App\Models\Tenant;

class TenantPolicy
{
    public function importData(PlatformUser $user, Tenant $tenant): bool
    {
        return $user->isActive() && $user->canPlatform('platform.tenants.import');
    }
}
