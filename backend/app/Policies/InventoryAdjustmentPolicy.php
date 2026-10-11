<?php

namespace App\Policies;

use App\Models\InventoryAdjustment;
use App\Models\User;

class InventoryAdjustmentPolicy extends CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->permissions->can('inventory.view');
    }

    public function view(User $user, InventoryAdjustment $document): bool
    {
        return $this->viewAny($user) && $this->sameTenant((int) $document->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->permissions->can('inventory.adjust');
    }

    public function update(User $user, InventoryAdjustment $document): bool
    {
        return $this->permissions->can('inventory.adjust')
            && $this->sameTenant((int) $document->tenant_id);
    }

    public function delete(User $user, InventoryAdjustment $document): bool
    {
        return $this->update($user, $document);
    }

    public function post(User $user, InventoryAdjustment $document): bool
    {
        return $this->update($user, $document);
    }
}
