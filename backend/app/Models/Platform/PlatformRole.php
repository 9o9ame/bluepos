<?php

namespace App\Models\Platform;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['code', 'name', 'description', 'is_system', 'is_active'])]
class PlatformRole extends Model
{
    use HasPublicUlid;

    protected $table = 'platform_roles';

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<PlatformPermission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(PlatformPermission::class, 'platform_role_permissions')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<PlatformUser, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(PlatformUser::class, 'platform_user_roles')
            ->withTimestamps();
    }
}
