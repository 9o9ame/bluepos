<?php

namespace App\Models\Platform;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['platform_user_id', 'token_hash', 'attempts', 'expires_at', 'consumed_at'])]
#[Hidden(['token_hash'])]
class PlatformPasswordResetChallenge extends Model
{
    use HasPublicUlid;

    protected $table = 'platform_password_reset_challenges';

    protected function casts(): array
    {
        return [
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
}
