<?php

namespace App\Models\Platform;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'name', 'module', 'description'])]
class PlatformPermission extends Model
{
    use HasPublicUlid;

    protected $table = 'platform_permissions';
}
