<?php

namespace App\Policies;

use App\Models\AccountType;
use App\Models\User;

class AccountTypePolicy extends CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows('coa.view', 'coa.manage');
    }

    public function view(User $user, AccountType $accountType): bool
    {
        return $this->viewAny($user) && $this->sameTenant((int) $accountType->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->allows('coa.create', 'coa.manage');
    }

    public function update(User $user, AccountType $accountType): bool
    {
        return $this->allows('coa.edit', 'coa.manage')
            && $this->sameTenant((int) $accountType->tenant_id);
    }

    public function delete(User $user, AccountType $accountType): bool
    {
        return $this->allows('coa.delete', 'coa.manage')
            && $this->sameTenant((int) $accountType->tenant_id);
    }
}
