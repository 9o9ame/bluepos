<?php

namespace App\Models;

use App\Enums\DeviceStatus;
use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'branch_id',
    'warehouse_id',
    'name',
    'device_type',
    'status',
    'credential_hash',
    'enrollment_code_hash',
    'enrollment_expires_at',
    'registered_at',
    'approved_at',
    'approved_by',
    'trusted_until',
    'last_seen_at',
    'last_sync_at',
    'revoked_at',
    'app_version',
])]
#[Hidden(['credential_hash', 'enrollment_code_hash'])]
class Device extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected function casts(): array
    {
        return [
            'status' => DeviceStatus::class,
            'enrollment_expires_at' => 'datetime',
            'registered_at' => 'datetime',
            'approved_at' => 'datetime',
            'trusted_until' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isActive(): bool
    {
        return $this->status === DeviceStatus::Active;
    }

    public function isTrusted(): bool
    {
        return $this->isActive()
            && $this->trusted_until !== null
            && $this->trusted_until->isFuture();
    }
}
