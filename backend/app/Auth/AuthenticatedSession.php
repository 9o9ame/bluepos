<?php

namespace App\Auth;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;

final readonly class AuthenticatedSession
{
    public function __construct(
        public User $user,
        public Tenant $tenant,
        public Membership $membership,
        public Branch $branch,
        public Warehouse $warehouse,
        public ?Device $device = null,
    ) {}
}
