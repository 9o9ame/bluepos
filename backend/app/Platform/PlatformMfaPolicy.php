<?php

namespace App\Platform;

class PlatformMfaPolicy
{
    public function localBypassEnabled(): bool
    {
        return app()->environment('local')
            && config('security.platform_dev_bypass_mfa') === true;
    }
}
