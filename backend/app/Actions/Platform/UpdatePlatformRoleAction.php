<?php

namespace App\Actions\Platform;

use App\Exceptions\ApiException;
use App\Models\Platform\PlatformRole;
use App\Platform\PlatformAuditLogger;
use App\Platform\PlatformPrivilegeGuard;
use App\Support\IdentityNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdatePlatformRoleAction
{
    public function __construct(
        private readonly PlatformAuditLogger $audit,
        private readonly PlatformPrivilegeGuard $privileges,
    ) {}

    /**
     * @param  array{name?: string, description?: ?string, is_active?: bool, code?: string}  $data
     */
    public function execute(PlatformRole $role, array $data): PlatformRole
    {
        return DB::transaction(function () use ($role, $data): PlatformRole {
            $role = PlatformRole::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();
            if ($role->is_system) {
                $this->privileges->assertSystemRoleProtected($role, 'updated');
            }

            if (isset($data['code'])) {
                $code = IdentityNormalizer::platformRoleCode($data['code']);
                if (! IdentityNormalizer::isValidPlatformRoleCode($code) || strcasecmp($code, 'SUPER_ADMIN') === 0) {
                    throw ValidationException::withMessages([
                        'code' => 'This role code is invalid or reserved.',
                    ]);
                }
                if (PlatformRole::query()->whereRaw('upper(code) = ?', [$code])->whereKeyNot($role->id)->exists()) {
                    throw ValidationException::withMessages([
                        'code' => 'This role code is already in use.',
                    ]);
                }
                $role->code = $code;
            }

            if (isset($data['name'])) {
                $role->name = $data['name'];
            }
            if (array_key_exists('description', $data)) {
                $role->description = $data['description'];
            }
            if (array_key_exists('is_active', $data)) {
                $role->is_active = (bool) $data['is_active'];
            }
            $role->save();

            $this->audit->record(
                array_key_exists('is_active', $data) && $data['is_active'] === false
                    ? 'PLATFORM_ROLE_DEACTIVATED'
                    : 'PLATFORM_ROLE_UPDATED',
                [
                    'resource_type' => 'platform_role',
                    'resource_ulid' => $role->ulid,
                    'code' => $role->code,
                ]
            );

            return $role->fresh() ?? $role;
        });
    }
}
