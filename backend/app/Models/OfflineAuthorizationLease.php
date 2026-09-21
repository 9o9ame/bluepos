<?php

namespace App\Models;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'user_id',
    'membership_id',
    'device_id',
    'branch_id',
    'security_version',
    'permission_snapshot',
    'signature',
    'issued_at',
    'expires_at',
    'revoked_at',
])]
#[Hidden(['signature'])]
class OfflineAuthorizationLease extends Model
{
    use HasPublicUlid;

    protected function casts(): array
    {
        return [
            'security_version' => 'integer',
            'permission_snapshot' => 'array',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function isExpired(): bool
    {
        return $this->revoked_at !== null || $this->expires_at->isPast();
    }
}
