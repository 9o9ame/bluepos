<?php

namespace App\Policies;

use App\Models\User;

class StockBalancePolicy extends CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->permissions->can('inventory.view');
    }
}
