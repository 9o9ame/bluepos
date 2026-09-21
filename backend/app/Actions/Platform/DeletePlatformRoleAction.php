<?php

namespace App\Actions\Platform;

use App\Exceptions\ApiException;
use App\Models\Platform\PlatformRole;
use App\Platform\PlatformAuditLogger;
use App\Platform\PlatformPrivilegeGuard;
use Illuminate\Support\Facades\DB;

class DeletePlatformRoleAction
{
    public function __construct(
        private readonly PlatformAuditLogger $audit,
        private readonly PlatformPrivilegeGuard $privileges,
    ) {}

    public function execute(PlatformRole $role): void
    {
        DB::transaction(function () use ($role): void {
            $role = PlatformRole::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();
            $this->privileges->assertSystemRoleProtected($role, 'deleted');

            if ($role->users()->exists()) {
                throw new ApiException('ROLE_IN_USE', 'The role is assigned to one or more users.', 409);
            }

            $role->permissions()->detach();
            $this->audit->record('PLATFORM_ROLE_UPDATED', [
                'resource_type' => 'platform_role',
                'resource_ulid' => $role->ulid,
                'code' => $role->code,
                'deleted' => true,
            ]);
            $role->delete();
        });
    }
}
