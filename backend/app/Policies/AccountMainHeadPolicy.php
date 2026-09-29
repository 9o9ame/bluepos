<?php

namespace App\Policies;

use App\Models\AccountMainHead;
use App\Models\User;

class AccountMainHeadPolicy extends CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows('coa.view', 'coa.manage');
    }

    public function view(User $user, AccountMainHead $mainHead): bool
    {
        return $this->viewAny($user) && $this->sameTenant((int) $mainHead->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->allows('coa.create', 'coa.manage');
    }

    public function update(User $user, AccountMainHead $mainHead): bool
    {
        return $this->allows('coa.edit', 'coa.manage')
            && $this->sameTenant((int) $mainHead->tenant_id);
    }

    public function delete(User $user, AccountMainHead $mainHead): bool
    {
        return $this->allows('coa.delete', 'coa.manage')
            && $this->sameTenant((int) $mainHead->tenant_id);
    }
}
