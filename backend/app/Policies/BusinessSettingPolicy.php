<?php

namespace App\Policies;

use App\Models\BusinessSetting;
use App\Models\User;

class BusinessSettingPolicy extends CatalogPolicy
{
    public function view(User $user, BusinessSetting $settings): bool
    {
        return $this->allows('settings.view', 'settings.manage')
            && $this->sameTenant((int) $settings->tenant_id);
    }

    public function update(User $user, BusinessSetting $settings): bool
    {
        return $this->permissions->can('settings.manage')
            && $this->sameTenant((int) $settings->tenant_id);
    }
}
