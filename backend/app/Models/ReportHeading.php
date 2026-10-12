<?php

namespace App\Models;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'tenant_id',
    'head_id',
    'used_for',
    'content_html',
])]
class ReportHeading extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;
}
