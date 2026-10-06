<?php

namespace App\Policies;

use App\Models\InventoryStockTake;
use App\Models\User;

class InventoryStockTakePolicy extends CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->permissions->can('inventory.view');
    }

    public function view(User $user, InventoryStockTake $document): bool
    {
        return $this->viewAny($user) && $this->sameTenant((int) $document->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->permissions->can('inventory.adjust');
    }

    public function update(User $user, InventoryStockTake $document): bool
    {
        return $this->permissions->can('inventory.adjust')
            && $this->sameTenant((int) $document->tenant_id);
    }

    public function delete(User $user, InventoryStockTake $document): bool
    {
        return $this->update($user, $document);
    }

    public function post(User $user, InventoryStockTake $document): bool
    {
        return $this->permissions->can('inventory.stock_take.approve')
            && $this->sameTenant((int) $document->tenant_id);
    }
}
