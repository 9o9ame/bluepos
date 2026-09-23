<?php

namespace App\Policies;

use App\Models\InventoryOpeningBalance;
use App\Models\User;

class InventoryOpeningBalancePolicy extends CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->permissions->can('inventory.opening_balance.view')
            || $this->permissions->can('inventory.view');
    }

    public function view(User $user, InventoryOpeningBalance $document): bool
    {
        return $this->viewAny($user) && $this->sameTenant((int) $document->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->permissions->can('inventory.opening_balance.create');
    }

    public function update(User $user, InventoryOpeningBalance $document): bool
    {
        return $this->permissions->can('inventory.opening_balance.edit')
            && $this->sameTenant((int) $document->tenant_id);
    }

    public function post(User $user, InventoryOpeningBalance $document): bool
    {
        return $this->permissions->can('inventory.opening_balance.post')
            && $this->sameTenant((int) $document->tenant_id);
    }

    public function delete(User $user, InventoryOpeningBalance $document): bool
    {
        return $this->permissions->can('inventory.opening_balance.edit')
            && $this->sameTenant((int) $document->tenant_id);
    }
}
