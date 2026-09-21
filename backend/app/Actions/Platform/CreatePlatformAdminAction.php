<?php

namespace App\Actions\Platform;

use App\Enums\PlatformUserStatus;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use App\Platform\PlatformAuditLogger;
use App\Platform\PlatformCatalogSync;
use App\Platform\PlatformPermissionCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreatePlatformAdminAction
{
    public function __construct(
        private readonly PlatformCatalogSync $catalog,
        private readonly PlatformAuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string, must_change_password?: bool}  $data
     */
    public function execute(array $data): PlatformUser
    {
        $this->catalog->ensure();
        $email = strtolower(trim($data['email']));

        return DB::transaction(function () use ($data, $email): PlatformUser {
            if (PlatformUser::query()->where('email', $email)->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'This platform email is already in use.',
                ]);
            }

            $user = PlatformUser::query()->create([
                'name' => $data['name'],
                'email' => $email,
                'password' => $data['password'],
                'status' => PlatformUserStatus::Active,
                'must_change_password' => (bool) ($data['must_change_password'] ?? true),
                'security_version' => 1,
            ]);

            $role = PlatformRole::query()->where('code', PlatformPermissionCatalogue::SUPER_ADMIN)->firstOrFail();
            $user->roles()->attach($role->id, ['ulid' => (string) Str::ulid()]);

            $this->audit->record('PLATFORM_ADMIN_CREATED', [
                'resource_type' => 'platform_user',
                'resource_ulid' => $user->ulid,
            ], null, $user);

            return $user->fresh(['roles']) ?? $user;
        });
    }
}
