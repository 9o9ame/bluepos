<?php

namespace App\Models;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'tenant_id',
    'title',
    'query_text',
    'created_by',
    'updated_by',
])]
class CustomQuery extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;
}
