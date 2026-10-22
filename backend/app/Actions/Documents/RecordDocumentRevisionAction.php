<?php

namespace App\Actions\Documents;

use App\Models\DocumentRevision;
use App\Tenancy\TenantContext;

class RecordDocumentRevisionAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    public function execute(
        string $documentType,
        string $documentUlid,
        string $reason,
        array $before,
        array $after,
        ?int $branchId = null,
        ?int $warehouseId = null,
    ): DocumentRevision {
        $tenantId = $this->tenantContext->tenantId();

        $lastRevision = DocumentRevision::query()
            ->forTenant($tenantId)
            ->where('document_type', $documentType)
            ->where('document_ulid', $documentUlid)
            ->lockForUpdate()
            ->max('revision_number');

        return DocumentRevision::query()->create([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'warehouse_id' => $warehouseId,
            'document_type' => $documentType,
            'document_ulid' => $documentUlid,
            'revision_number' => ((int) $lastRevision) + 1,
            'reason' => trim($reason),
            'before_snapshot' => $before,
            'after_snapshot' => $after,
            'created_by' => $this->tenantContext->userId(),
        ]);
    }
}
