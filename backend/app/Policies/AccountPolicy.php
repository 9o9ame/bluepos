<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

class AccountPolicy extends CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows('accounts.view', 'accounts.manage');
    }

    public function view(User $user, Account $account): bool
    {
        return $this->viewAny($user) && $this->sameTenant((int) $account->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->allows('accounts.create', 'accounts.manage');
    }

    public function update(User $user, Account $account): bool
    {
        return $this->allows('accounts.edit', 'accounts.manage')
            && $this->sameTenant((int) $account->tenant_id);
    }

    public function delete(User $user, Account $account): bool
    {
        return $this->allows('accounts.delete', 'accounts.manage')
            && $this->sameTenant((int) $account->tenant_id);
    }
}
