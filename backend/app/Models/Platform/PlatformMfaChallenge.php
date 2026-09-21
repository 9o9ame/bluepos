<?php

namespace App\Models\Platform;

use App\Enums\MfaMethod;
use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'platform_user_id',
    'platform_device_id',
    'method',
    'purpose',
    'code_hash',
    'attempts',
    'expires_at',
    'consumed_at',
])]
#[Hidden(['code_hash'])]
class PlatformMfaChallenge extends Model
{
    use HasPublicUlid;

    protected $table = 'platform_mfa_challenges';

    protected function casts(): array
    {
        return [
            'method' => MfaMethod::class,
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PlatformUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'platform_user_id');
    }

    /**
     * @return BelongsTo<PlatformDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(PlatformDevice::class, 'platform_device_id');
    }
}
