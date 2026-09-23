<?php

namespace App\Models\Platform;

use App\Enums\PlatformUserStatus;
use App\Platform\PlatformPermissionCatalogue;
use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable([
    'name',
    'email',
    'password',
    'status',
    'must_change_password',
    'password_changed_at',
    'security_version',
    'last_login_at',
    'last_mfa_verified_at',
])]
#[Hidden(['password', 'remember_token'])]
class PlatformUser extends Authenticatable
{
    use HasPublicUlid, Notifiable;

    protected $table = 'platform_users';

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => PlatformUserStatus::class,
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'security_version' => 'integer',
            'last_login_at' => 'datetime',
            'last_mfa_verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<PlatformRole, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(PlatformRole::class, 'platform_user_roles')
            ->withTimestamps();
    }

    /**
     * @return HasMany<PlatformSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(PlatformSession::class);
    }

    public function isActive(): bool
    {
        return $this->status === PlatformUserStatus::Active;
    }

    public function isSuperAdmin(): bool
    {
        return $this->roles()
            ->where('platform_roles.code', PlatformPermissionCatalogue::SUPER_ADMIN)
            ->where('platform_roles.is_active', true)
            ->exists();
    }

    /**
     * @return list<string>
     */
    public function permissionKeys(): array
    {
        return $this->roles()
            ->where('platform_roles.is_active', true)
            ->with('permissions')
            ->get()
            ->flatMap(fn (PlatformRole $role) => $role->permissions->pluck('key'))
            ->unique()
            ->values()
            ->all();
    }

    public function canPlatform(string $key): bool
    {
        return in_array($key, $this->permissionKeys(), true);
    }

    public function bumpSecurityVersion(): void
    {
        $this->security_version = (int) $this->security_version + 1;
        $this->setRememberToken(Str::random(60));
        $this->save();
    }
}
