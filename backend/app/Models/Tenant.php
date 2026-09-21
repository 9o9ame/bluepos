<?php

namespace App\Models;

use App\Enums\TenantStatus;
use App\Support\HasPublicUlid;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'code', 'legal_name', 'status', 'timezone', 'currency_code', 'security_version'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasPublicUlid;

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'security_version' => 'integer',
        ];
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return HasMany<Branch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /**
     * @return HasMany<Warehouse, $this>
     */
    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function subscription(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(TenantSubscription::class);
    }

    /**
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * @return HasMany<TenantFeatureOverride, $this>
     */
    public function featureOverrides(): HasMany
    {
        return $this->hasMany(TenantFeatureOverride::class);
    }

    /**
     * @return HasMany<TenantLimitOverride, $this>
     */
    public function limitOverrides(): HasMany
    {
        return $this->hasMany(TenantLimitOverride::class);
    }

    public function bumpSecurityVersion(): void
    {
        $this->security_version = (int) $this->security_version + 1;
        $this->save();
    }
}
