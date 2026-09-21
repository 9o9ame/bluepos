<?php

namespace App\Models\Platform;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'platform_user_id',
    'platform_device_id',
    'laravel_session_id',
    'security_version',
    'ip_address',
    'user_agent',
    'last_seen_at',
    'revoked_at',
])]
class PlatformSession extends Model
{
    use HasPublicUlid;

    protected $table = 'platform_sessions';

    protected function casts(): array
    {
        return [
            'security_version' => 'integer',
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

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
