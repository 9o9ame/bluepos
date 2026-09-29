<?php

namespace App\Policies;

use App\Models\AccountSubHead;
use App\Models\User;

class AccountSubHeadPolicy extends CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows('coa.view', 'coa.manage');
    }

    public function view(User $user, AccountSubHead $subHead): bool
    {
        return $this->viewAny($user) && $this->sameTenant((int) $subHead->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->allows('coa.create', 'coa.manage');
    }

    public function update(User $user, AccountSubHead $subHead): bool
    {
        return $this->allows('coa.edit', 'coa.manage')
            && $this->sameTenant((int) $subHead->tenant_id);
    }

    public function delete(User $user, AccountSubHead $subHead): bool
    {
        return $this->allows('coa.delete', 'coa.manage')
            && $this->sameTenant((int) $subHead->tenant_id);
    }
}
