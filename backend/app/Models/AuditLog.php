<?php

namespace App\Models;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'tenant_id',
    'actor_user_id',
    'actor_membership_id',
    'device_id',
    'branch_id',
    'event',
    'resource_type',
    'resource_ulid',
    'metadata',
    'ip_address',
    'user_agent',
    'occurred_at',
])]
class AuditLog extends Model
{
    use HasPublicUlid;

    protected static function booted(): void
    {
        static::updating(function (): bool {
            return false;
        });

        static::deleting(function (): bool {
            return false;
        });
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
