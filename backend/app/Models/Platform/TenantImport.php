<?php

namespace App\Models\Platform;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;

class TenantImport extends Model
{
    use HasPublicUlid;

    protected $table = 'platform_tenant_imports';

    protected $guarded = ['id'];
}
