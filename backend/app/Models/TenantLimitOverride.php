<?php

namespace App\Models;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'limit_key', 'value', 'reason', 'actor_platform_user_id'])]
class TenantLimitOverride extends Model
{
    use HasPublicUlid;

    protected function casts(): array
    {
        return [
            'value' => 'integer',
        ];
    }
}
