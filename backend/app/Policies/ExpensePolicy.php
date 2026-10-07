<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

class ExpensePolicy extends CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->permissions->can('expenses.view');
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->permissions->can('expenses.view')
            && $this->sameTenant((int) $expense->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->permissions->can('expenses.create');
    }
}
