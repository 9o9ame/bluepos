<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Support\HasPublicUlid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable([
    'name',
    'email',
    'password',
    'status',
    'last_login_at',
    'recovery_email',
    'recovery_phone',
    'must_change_password',
    'password_changed_at',
    'security_version',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPublicUlid, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'must_change_password' => 'boolean',
            'security_version' => 'integer',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    /**
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null || trim($value) === ''
                ? null
                : Str::lower(trim($value)),
        );
    }

    public function recoveryAddress(): ?string
    {
        return $this->recovery_email ?: $this->email;
    }

    public function bumpSecurityVersion(): void
    {
        $this->security_version = (int) $this->security_version + 1;
        $this->save();
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
