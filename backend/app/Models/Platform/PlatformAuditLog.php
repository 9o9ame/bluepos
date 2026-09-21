<?php

namespace App\Models\Platform;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'actor_platform_user_id',
    'event',
    'resource_type',
    'resource_ulid',
    'metadata',
    'ip_address',
    'user_agent',
    'occurred_at',
])]
class PlatformAuditLog extends Model
{
    use HasPublicUlid;

    protected $table = 'platform_audit_logs';

    protected static function booted(): void
    {
        static::updating(fn (): bool => false);
        static::deleting(fn (): bool => false);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'actor_platform_user_id');
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
