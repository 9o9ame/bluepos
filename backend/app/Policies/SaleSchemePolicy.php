<?php

namespace App\Policies;

use App\Models\SaleScheme;
use App\Models\User;

class SaleSchemePolicy extends CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows('sale_schemes.view', 'sale_schemes.manage');
    }

    public function view(User $user, SaleScheme $scheme): bool
    {
        return $this->viewAny($user) && $this->sameTenant((int) $scheme->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->allows('sale_schemes.create', 'sale_schemes.manage');
    }

    public function update(User $user, SaleScheme $scheme): bool
    {
        return $this->allows('sale_schemes.edit', 'sale_schemes.manage')
            && $this->sameTenant((int) $scheme->tenant_id);
    }

    public function delete(User $user, SaleScheme $scheme): bool
    {
        return $this->allows('sale_schemes.delete', 'sale_schemes.manage')
            && $this->sameTenant((int) $scheme->tenant_id);
    }
}
