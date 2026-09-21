<?php

namespace App\Models\Platform;

use App\Enums\DeviceStatus;
use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'platform_user_id',
    'name',
    'status',
    'credential_hash',
    'registered_at',
    'trusted_until',
    'last_seen_at',
    'revoked_at',
])]
#[Hidden(['credential_hash'])]
class PlatformDevice extends Model
{
    use HasPublicUlid;

    protected $table = 'platform_devices';

    protected function casts(): array
    {
        return [
            'status' => DeviceStatus::class,
            'registered_at' => 'datetime',
            'trusted_until' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PlatformUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'platform_user_id');
    }

    public function isTrusted(): bool
    {
        return $this->status === DeviceStatus::Active
            && $this->trusted_until !== null
            && $this->trusted_until->isFuture();
    }
}
