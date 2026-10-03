<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;

class SalePolicy extends CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->permissions->can('sales.view');
    }

    public function view(User $user, Sale $sale): bool
    {
        return $this->permissions->can('sales.view')
            && $this->sameTenant((int) $sale->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->permissions->can('sales.create');
    }

    public function createPayment(User $user, Sale $sale): bool
    {
        return $this->permissions->can('payments.create')
            && $this->sameTenant((int) $sale->tenant_id);
    }
}
