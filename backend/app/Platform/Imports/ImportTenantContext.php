<?php

namespace App\Platform\Imports;

use App\Models\Tenant;
use App\Tenancy\TenantContext;

/** Explicit platform execution context; never hydrates a tenant user/membership. */
final class ImportTenantContext extends TenantContext
{
    public function __construct(private readonly Tenant $target) {}

    public function tenant(): Tenant
    {
        return $this->target;
    }

    public function userId(): ?int
    {
        return null;
    }
}
