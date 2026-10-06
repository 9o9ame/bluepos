<?php

namespace App\Policies;

use App\Models\SaleReturn;
use App\Models\User;

class SaleReturnPolicy extends CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->permissions->can('sales.return');
    }

    public function view(User $user, SaleReturn $document): bool
    {
        return $this->permissions->can('sales.return')
            && $this->sameTenant((int) $document->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->permissions->can('sales.return');
    }

    public function update(User $user, SaleReturn $document): bool
    {
        return $this->permissions->can('sales.return')
            && $this->sameTenant((int) $document->tenant_id);
    }

    public function post(User $user, SaleReturn $document): bool
    {
        return $this->permissions->can('sales.return')
            && $this->sameTenant((int) $document->tenant_id);
    }

    public function refund(User $user, SaleReturn $document): bool
    {
        return $this->permissions->can('sales.return')
            && $this->permissions->can('payments.create')
            && $this->sameTenant((int) $document->tenant_id);
    }
}
