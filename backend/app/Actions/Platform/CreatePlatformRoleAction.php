<?php

namespace App\Actions\Platform;

use App\Models\Platform\PlatformRole;
use App\Platform\PlatformAuditLogger;
use App\Platform\PlatformCatalogSync;
use App\Support\IdentityNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreatePlatformRoleAction
{
    public function __construct(
        private readonly PlatformCatalogSync $catalog,
        private readonly PlatformAuditLogger $audit,
    ) {}

    /**
     * @param  array{code: string, name: string, description?: ?string}  $data
     */
    public function execute(array $data): PlatformRole
    {
        $this->catalog->ensure();
        $code = IdentityNormalizer::platformRoleCode($data['code']);
        if (! IdentityNormalizer::isValidPlatformRoleCode($code) || strcasecmp($code, 'SUPER_ADMIN') === 0) {
            throw ValidationException::withMessages([
                'code' => 'This role code is invalid or reserved.',
            ]);
        }

        return DB::transaction(function () use ($data, $code): PlatformRole {
            if (PlatformRole::query()->whereRaw('upper(code) = ?', [$code])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'code' => 'This role code is already in use.',
                ]);
            }

            $role = PlatformRole::query()->create([
                'code' => $code,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_system' => false,
                'is_active' => true,
            ]);

            $this->audit->record('PLATFORM_ROLE_CREATED', [
                'resource_type' => 'platform_role',
                'resource_ulid' => $role->ulid,
                'code' => $role->code,
            ]);

            return $role;
        });
    }
}
