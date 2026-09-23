<?php

namespace App\Policies;

use App\Models\PurchaseReturn;
use App\Models\User;

class PurchaseReturnPolicy extends CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->permissions->can('purchase_returns.view');
    }

    public function view(User $user, PurchaseReturn $document): bool
    {
        return $this->permissions->can('purchase_returns.view')
            && $this->sameTenant((int) $document->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->permissions->can('purchase_returns.create');
    }

    public function update(User $user, PurchaseReturn $document): bool
    {
        return $this->permissions->can('purchase_returns.edit')
            && $this->sameTenant((int) $document->tenant_id);
    }

    public function post(User $user, PurchaseReturn $document): bool
    {
        return $this->permissions->can('purchase_returns.post')
            && $this->sameTenant((int) $document->tenant_id);
    }
}
