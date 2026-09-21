<?php

namespace App\Models;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'name', 'module', 'description', 'is_active'])]
class FeatureDefinition extends Model
{
    use HasPublicUlid;

    protected $table = 'feature_catalog';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
