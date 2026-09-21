<?php

namespace App\Models;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'user_id',
    'membership_id',
    'device_id',
    'laravel_session_id',
    'security_version',
    'ip_address',
    'user_agent',
    'last_seen_at',
    'revoked_at',
])]
class AuthSessionRecord extends Model
{
    use HasPublicUlid;

    protected $table = 'auth_sessions';

    protected function casts(): array
    {
        return [
            'security_version' => 'integer',
            'last_seen_at' => 'datetime',
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

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
