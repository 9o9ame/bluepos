<?php

namespace App\Models\Platform;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;

class TenantImportRow extends Model
{
    use HasPublicUlid;

    protected $table = 'platform_tenant_import_rows';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['result' => 'array'];
    }
}
