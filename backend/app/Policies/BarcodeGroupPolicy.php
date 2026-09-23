<?php

namespace App\Policies;

use App\Models\BarcodeGroup;
use App\Models\User;

class BarcodeGroupPolicy extends CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->permissions->can('barcode_groups.view');
    }

    public function view(User $user, BarcodeGroup $barcodeGroup): bool
    {
        return $this->viewAny($user) && $this->sameTenant((int) $barcodeGroup->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->permissions->can('barcode_groups.create');
    }

    public function update(User $user, BarcodeGroup $barcodeGroup): bool
    {
        return $this->permissions->can('barcode_groups.edit')
            && $this->sameTenant((int) $barcodeGroup->tenant_id);
    }

    public function delete(User $user, BarcodeGroup $barcodeGroup): bool
    {
        return $this->permissions->can('barcode_groups.delete')
            && $this->sameTenant((int) $barcodeGroup->tenant_id);
    }
}
