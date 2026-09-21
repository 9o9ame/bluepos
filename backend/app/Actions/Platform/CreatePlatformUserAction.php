<?php

namespace App\Actions\Platform;

use App\Enums\PlatformUserStatus;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use App\Platform\PlatformAuditLogger;
use App\Platform\PlatformCatalogSync;
use App\Platform\PlatformContext;
use App\Platform\PlatformPermissionCatalogue;
use App\Platform\PlatformPrivilegeGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreatePlatformUserAction
{
    public function __construct(
        private readonly PlatformCatalogSync $catalog,
        private readonly PlatformAuditLogger $audit,
        private readonly PlatformPrivilegeGuard $privileges,
        private readonly PlatformContext $context,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string, must_change_password?: bool, status?: string, role_ulids?: list<string>, reason?: ?string}  $data
     */
    public function execute(Request $request, array $data): PlatformUser
    {
        $this->catalog->ensure();
        $email = strtolower(trim($data['email']));
        $roleUlids = array_values(array_unique($data['role_ulids'] ?? []));

        return DB::transaction(function () use ($request, $data, $email, $roleUlids): PlatformUser {
            if (PlatformUser::query()->where('email', $email)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'This platform email is already in use.',
                ]);
            }

            $roles = collect();
            if ($roleUlids !== []) {
                $roles = PlatformRole::query()
                    ->with('permissions')
                    ->whereIn('ulid', $roleUlids)
                    ->lockForUpdate()
                    ->get();
                if ($roles->count() !== count($roleUlids)) {
                    throw ValidationException::withMessages([
                        'role_ulids' => 'One or more roles were not found.',
                    ]);
                }
                foreach ($roles as $role) {
                    if (! $role->is_active) {
                        throw ValidationException::withMessages([
                            'role_ulids' => 'Inactive roles cannot be assigned.',
                        ]);
                    }
                }
                $this->privileges->assertCanAssignRoles(
                    $this->context->user(),
                    $roles,
                    $request,
                    $data['reason'] ?? null,
                );
            }

            $status = $data['status'] ?? PlatformUserStatus::Active->value;
            $user = PlatformUser::query()->create([
                'name' => $data['name'],
                'email' => $email,
                'password' => $data['password'],
                'status' => $status,
                'must_change_password' => (bool) ($data['must_change_password'] ?? true),
                'security_version' => 1,
            ]);

            foreach ($roles as $role) {
                $user->roles()->attach($role->id, ['ulid' => (string) Str::ulid()]);
            }

            $this->audit->record('PLATFORM_USER_CREATED', [
                'resource_type' => 'platform_user',
                'resource_ulid' => $user->ulid,
                'role_codes' => $roles->pluck('code')->values()->all(),
            ]);

            if ($roles->contains(fn (PlatformRole $role): bool => $role->code === PlatformPermissionCatalogue::SUPER_ADMIN)) {
                $this->audit->record('PLATFORM_SUPER_ADMIN_GRANTED', [
                    'resource_type' => 'platform_user',
                    'resource_ulid' => $user->ulid,
                    'reason' => $data['reason'] ?? null,
                ]);
            }

            return $user->fresh(['roles']) ?? $user;
        });
    }
}
