<?php

namespace App\Actions\Purchases;

use App\Catalog\TenantCatalog;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\WarehouseStatus;
use App\Models\PurchaseInvoice;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreatePurchaseInvoiceAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantCatalog $catalog,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{
     *     supplier_ulid: string,
     *     warehouse_ulid: string,
     *     invoice_date?: string,
     *     due_date?: string|null,
     *     supplier_invoice_number?: string|null,
     *     freight_amount?: string,
     *     other_charges?: string,
     *     notes?: string|null
     * }  $data
     */
    public function execute(array $data): PurchaseInvoice
    {
        return DB::transaction(function () use ($data): PurchaseInvoice {
            $tenantId = $this->tenantContext->tenantId();
            $supplier = $this->catalog->supplier($data['supplier_ulid']);
            $warehouse = $this->catalog->warehouse($data['warehouse_ulid']);

            if (! $supplier->is_active) {
                throw ValidationException::withMessages([
                    'supplier_ulid' => 'Purchases cannot use an inactive supplier.',
                ]);
            }
            if ($warehouse->status !== WarehouseStatus::Active) {
                throw ValidationException::withMessages([
                    'warehouse_ulid' => 'Purchases cannot use an inactive warehouse.',
                ]);
            }

            $invoice = PurchaseInvoice::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'supplier_id' => $supplier->id,
                'document_number' => $this->nextDocumentNumber($tenantId),
                'supplier_invoice_number' => $data['supplier_invoice_number'] ?? null,
                'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
                'due_date' => $data['due_date'] ?? null,
                'status' => PurchaseInvoiceStatus::Draft,
                'subtotal' => '0.0000',
                'discount_amount' => '0.0000',
                'tax_amount' => '0.0000',
                'freight_amount' => $this->money($data['freight_amount'] ?? '0'),
                'other_charges' => $this->money($data['other_charges'] ?? '0'),
                'grand_total' => '0.0000',
                'notes' => $data['notes'] ?? null,
                'created_by' => $this->tenantContext->userId(),
            ]);

            $invoice->grand_total = bcadd((string) $invoice->freight_amount, (string) $invoice->other_charges, 4);
            $invoice->save();

            $this->audit->record('PURCHASE_CREATED', [
                'resource_type' => 'purchase_invoice',
                'resource_ulid' => $invoice->ulid,
            ]);

            return $invoice->fresh(static::with()) ?? $invoice;
        });
    }

    /**
     * @return list<string>
     */
    public static function with(): array
    {
        return ['supplier', 'branch', 'warehouse', 'lines.product', 'lines.unit'];
    }

    private function nextDocumentNumber(int $tenantId): string
    {
        $latest = PurchaseInvoice::query()
            ->forTenant($tenantId)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('document_number');

        $seq = 1;
        if (is_string($latest) && preg_match('/PUR-(\d+)$/', $latest, $matches) === 1) {
            $seq = (int) $matches[1] + 1;
        }

        return 'PUR-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }

    private function money(string $value): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages([
                'amount' => 'Amount must be a valid non-negative decimal.',
            ]);
        }

        return bcadd($value, '0', 4);
    }
}
