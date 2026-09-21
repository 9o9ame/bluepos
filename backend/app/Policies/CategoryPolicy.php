<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

class CategoryPolicy extends CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows('categories.view', 'categories.manage');
    }

    public function view(User $user, Category $category): bool
    {
        return $this->viewAny($user) && $this->sameTenant((int) $category->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->allows('categories.create', 'categories.manage');
    }

    public function update(User $user, Category $category): bool
    {
        return $this->allows('categories.edit', 'categories.manage')
            && $this->sameTenant((int) $category->tenant_id);
    }

    public function delete(User $user, Category $category): bool
    {
        return $this->allows('categories.delete', 'categories.manage')
            && $this->sameTenant((int) $category->tenant_id);
    }
}
