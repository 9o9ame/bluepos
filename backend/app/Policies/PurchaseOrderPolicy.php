<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;

class PurchaseOrderPolicy extends CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->permissions->can('purchases.view');
    }

    public function view(User $user, PurchaseOrder $order): bool
    {
        return $this->permissions->can('purchases.view')
            && $this->sameTenant((int) $order->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->permissions->can('purchases.create');
    }

    public function cancel(User $user, PurchaseOrder $order): bool
    {
        return $this->permissions->can('purchases.edit')
            && $this->sameTenant((int) $order->tenant_id);
    }
}
