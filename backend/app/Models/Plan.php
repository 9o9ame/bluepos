<?php

namespace App\Models;

use App\Enums\PlanStatus;
use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'description', 'status', 'billing_interval'])]
class Plan extends Model
{
    use HasPublicUlid;

    protected function casts(): array
    {
        return [
            'status' => PlanStatus::class,
        ];
    }

    /**
     * @return HasMany<PlanFeature, $this>
     */
    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    /**
     * @return HasMany<PlanLimit, $this>
     */
    public function limits(): HasMany
    {
        return $this->hasMany(PlanLimit::class);
    }
}
