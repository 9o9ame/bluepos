<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy extends CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows('customers.view', 'customers.manage');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->viewAny($user) && $this->sameTenant((int) $customer->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->allows('customers.create', 'customers.manage');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->allows('customers.edit', 'customers.manage')
            && $this->sameTenant((int) $customer->tenant_id);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->allows('customers.delete', 'customers.manage')
            && $this->sameTenant((int) $customer->tenant_id);
    }
}
