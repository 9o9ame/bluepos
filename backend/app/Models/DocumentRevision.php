<?php

namespace App\Models;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'tenant_id',
    'branch_id',
    'warehouse_id',
    'document_type',
    'document_ulid',
    'revision_number',
    'reason',
    'idempotency_key',
    'before_snapshot',
    'after_snapshot',
    'created_by',
])]
class DocumentRevision extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected function casts(): array
    {
        return [
            'revision_number' => 'integer',
            'before_snapshot' => 'array',
            'after_snapshot' => 'array',
        ];
    }
}
