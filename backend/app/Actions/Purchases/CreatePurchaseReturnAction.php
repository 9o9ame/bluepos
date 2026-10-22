<?php

namespace App\Actions\Purchases;

use App\Catalog\TenantCatalog;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseReturnStatus;
use App\Enums\WarehouseStatus;
use App\Exceptions\ApiException;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreatePurchaseReturnAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantCatalog $catalog,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{
     *     purchase_ulid?: string|null,
     *     supplier_ulid?: string|null,
     *     warehouse_ulid?: string|null,
     *     return_date?: string,
     *     supplier_reference?: string|null,
     *     reason?: string|null,
     *     notes?: string|null
     * }  $data
     */
    public function execute(array $data): PurchaseReturn
    {
        return DB::transaction(function () use ($data): PurchaseReturn {
            $tenantId = $this->tenantContext->tenantId();
            $purchaseUlid = trim((string) ($data['purchase_ulid'] ?? ''));

            $invoice = null;
            $supplier = null;

            if ($purchaseUlid !== '') {
                $invoice = PurchaseInvoice::query()
                    ->forTenant($tenantId)
                    ->where('branch_id', $this->tenantContext->branchId())
                    ->where('warehouse_id', $this->tenantContext->warehouseId())
                    ->with(['supplier', 'warehouse'])
                    ->where('ulid', $purchaseUlid)
                    ->lockForUpdate()
                    ->first();

                if (! $invoice) {
                    throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
                }

                if ($invoice->status !== PurchaseInvoiceStatus::Posted) {
                    throw ValidationException::withMessages([
                        'purchase_ulid' => 'Purchase returns can only be created from posted purchase invoices.',
                    ]);
                }

                if ($invoice->latestRevision()->exists()) {
                    throw new ApiException(
                        'PURCHASE_RETURN_SOURCE_AMENDED',
                        'This purchase has an amendment revision. Return source lines must be revision-aware before a new return can be created.',
                        409,
                    );
                }

                $supplier = $invoice->supplier;
            } else {
                $supplierUlid = trim((string) ($data['supplier_ulid'] ?? ''));
                if ($supplierUlid === '') {
                    throw ValidationException::withMessages([
                        'supplier_ulid' => 'Supplier is required for a general purchase return.',
                    ]);
                }

                $supplier = $this->catalog->supplier($supplierUlid);
                if (! $supplier->is_active) {
                    throw ValidationException::withMessages([
                        'supplier_ulid' => 'Purchase returns cannot use an inactive supplier.',
                    ]);
                }
            }

            $warehouse = $invoice?->warehouse;
            if (! empty($data['warehouse_ulid'])) {
                $warehouse = $this->catalog->warehouse((string) $data['warehouse_ulid']);
            }

            if (! $warehouse || $warehouse->status !== WarehouseStatus::Active) {
                throw ValidationException::withMessages([
                    'warehouse_ulid' => 'Purchase returns cannot use an inactive warehouse.',
                ]);
            }

            if ((int) $warehouse->branch_id !== $this->tenantContext->branchId()
                || (int) $warehouse->id !== $this->tenantContext->warehouseId()) {
                throw ValidationException::withMessages([
                    'warehouse_ulid' => 'Switch to the selected branch and warehouse before creating this purchase return.',
                ]);
            }

            $document = PurchaseReturn::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'supplier_id' => $supplier->id,
                'purchase_invoice_id' => $invoice?->id,
                'document_number' => $this->nextDocumentNumber($tenantId),
                'return_date' => $data['return_date'] ?? now()->toDateString(),
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'status' => PurchaseReturnStatus::Draft,
                'subtotal' => '0.0000',
                'discount_amount' => '0.0000',
                'tax_amount' => '0.0000',
                'grand_total' => '0.0000',
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $this->tenantContext->userId(),
            ]);

            $this->audit->record('PURCHASE_RETURN_CREATED', [
                'resource_type' => 'purchase_return',
                'resource_ulid' => $document->ulid,
                'purchase_ulid' => $invoice?->ulid,
                'return_mode' => $invoice ? 'invoice' : 'general',
            ]);

            return $document->fresh(static::with()) ?? $document;
        });
    }

    /**
     * @return list<string>
     */
    public static function with(): array
    {
        return [
            'supplier',
            'branch',
            'warehouse',
            'purchaseInvoice',
            'lines.product',
            'lines.unit',
            'lines.purchaseInvoiceLine',
        ];
    }

    private function nextDocumentNumber(int $tenantId): string
    {
        $latest = PurchaseReturn::query()
            ->forTenant($tenantId)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('document_number');

        $seq = 1;
        if (is_string($latest) && preg_match('/PR-(\d+)$/', $latest, $matches) === 1) {
            $seq = (int) $matches[1] + 1;
        }

        return 'PR-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
