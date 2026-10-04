<?php

namespace App\Platform;

class PlatformMfaPolicy
{
    public function enabled(): bool
    {
        return config('security.platform_mfa_enabled') === true;
    }

    public function localBypassEnabled(): bool
    {
        return app()->environment('local')
            && config('security.platform_dev_bypass_mfa') === true;
    }
}
