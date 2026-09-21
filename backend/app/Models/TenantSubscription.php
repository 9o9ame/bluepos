<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'plan_id',
    'status',
    'trial_starts_at',
    'trial_ends_at',
    'starts_at',
    'ends_at',
    'grace_ends_at',
])]
class TenantSubscription extends Model
{
    use HasPublicUlid;

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'trial_starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'grace_ends_at' => 'datetime',
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
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isOperational(): bool
    {
        if (in_array($this->status, [SubscriptionStatus::Trial, SubscriptionStatus::Active], true)) {
            return true;
        }

        if ($this->status === SubscriptionStatus::PastDue && $this->grace_ends_at && $this->grace_ends_at->isFuture()) {
            return true;
        }

        return false;
    }
}
