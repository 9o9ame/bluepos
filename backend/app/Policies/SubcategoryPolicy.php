<?php

namespace App\Policies;

use App\Models\Subcategory;
use App\Models\User;

class SubcategoryPolicy extends CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows('categories.view', 'categories.manage');
    }

    public function view(User $user, Subcategory $subcategory): bool
    {
        return $this->viewAny($user) && $this->sameTenant((int) $subcategory->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->allows('categories.create', 'categories.manage');
    }

    public function update(User $user, Subcategory $subcategory): bool
    {
        return $this->allows('categories.edit', 'categories.manage')
            && $this->sameTenant((int) $subcategory->tenant_id);
    }

    public function delete(User $user, Subcategory $subcategory): bool
    {
        return $this->allows('categories.delete', 'categories.manage')
            && $this->sameTenant((int) $subcategory->tenant_id);
    }
}
