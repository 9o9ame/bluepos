<?php

namespace App\Models;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'code',
    'name',
    'deals_in',
    'contact_person',
    'mobile',
    'mobile_secondary',
    'phone',
    'phone_secondary',
    'email',
    'address',
    'billing_address',
    'is_active',
    'created_by',
    'updated_by',
])]
class Customer extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
