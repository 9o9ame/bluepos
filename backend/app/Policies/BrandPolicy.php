<?php

namespace App\Policies;

use App\Models\Brand;
use App\Models\User;

class BrandPolicy extends CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows('brands.view', 'brands.manage');
    }

    public function view(User $user, Brand $brand): bool
    {
        return $this->viewAny($user) && $this->sameTenant((int) $brand->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->allows('brands.create', 'brands.manage');
    }

    public function update(User $user, Brand $brand): bool
    {
        return $this->allows('brands.edit', 'brands.manage')
            && $this->sameTenant((int) $brand->tenant_id);
    }

    public function delete(User $user, Brand $brand): bool
    {
        return $this->allows('brands.delete', 'brands.manage')
            && $this->sameTenant((int) $brand->tenant_id);
    }
}
