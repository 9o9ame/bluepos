<?php

namespace App\Models;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'feature_key', 'enabled', 'reason', 'actor_platform_user_id'])]
class TenantFeatureOverride extends Model
{
    use HasPublicUlid;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }
}
