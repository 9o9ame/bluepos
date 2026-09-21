<?php

namespace App\Policies;

use App\Models\Unit;
use App\Models\User;

class UnitPolicy extends CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows('units.view', 'units.manage');
    }

    public function view(User $user, Unit $unit): bool
    {
        return $this->viewAny($user) && $this->sameTenant((int) $unit->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->allows('units.create', 'units.manage');
    }

    public function update(User $user, Unit $unit): bool
    {
        return $this->allows('units.edit', 'units.manage')
            && $this->sameTenant((int) $unit->tenant_id);
    }

    public function delete(User $user, Unit $unit): bool
    {
        return $this->allows('units.delete', 'units.manage')
            && $this->sameTenant((int) $unit->tenant_id);
    }
}
