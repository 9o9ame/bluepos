<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['party_profile_id', 'type'])]
class PartyProfileType extends Model
{
    public function profile(): BelongsTo
    {
        return $this->belongsTo(PartyProfile::class, 'party_profile_id');
    }
}
