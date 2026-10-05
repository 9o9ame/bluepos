<?php

namespace App\Models;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'tenant_id','code','name','deals_in','contact_person','mobile','mobile_secondary',
    'phone','phone_secondary','email','address','billing_address','area',
    'invoice_restricted','credit_limit_amount','credit_limit_days','add_percent',
    'cnic','ntn','stn','formulas','license_number','license_issued_on','license_type',
    'license_expires_on','ignore_warranty','print_license','rf_id','store_allowed',
    'image_path','is_active','created_by','updated_by',
])]
class PartyProfile extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'invoice_restricted' => 'boolean',
            'credit_limit_amount' => 'decimal:4',
            'credit_limit_days' => 'integer',
            'add_percent' => 'decimal:8',
            'license_issued_on' => 'date',
            'license_expires_on' => 'date',
            'ignore_warranty' => 'boolean',
            'print_license' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function types(): HasMany { return $this->hasMany(PartyProfileType::class); }
    public function supplier(): HasOne { return $this->hasOne(Supplier::class); }
    public function customer(): HasOne { return $this->hasOne(Customer::class); }

    public function hasType(string $type): bool
    {
        if ($this->relationLoaded('types')) {
            return $this->types->contains(fn (PartyProfileType $row): bool => $row->type === $type);
        }

        return $this->types()->where('type', $type)->exists();
    }
}
