<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

class SupplierPolicy extends CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows('suppliers.view', 'suppliers.manage');
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $this->viewAny($user) && $this->sameTenant((int) $supplier->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->allows('suppliers.create', 'suppliers.manage');
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $this->allows('suppliers.edit', 'suppliers.manage')
            && $this->sameTenant((int) $supplier->tenant_id);
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $this->allows('suppliers.delete', 'suppliers.manage')
            && $this->sameTenant((int) $supplier->tenant_id);
    }
}
