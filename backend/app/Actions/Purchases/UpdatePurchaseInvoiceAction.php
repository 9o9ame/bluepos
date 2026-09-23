<?php

namespace App\Actions\Purchases;

use App\Catalog\TenantCatalog;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\WarehouseStatus;
use App\Exceptions\ApiException;
use App\Models\PurchaseInvoice;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdatePurchaseInvoiceAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantCatalog $catalog,
        private readonly RecalculatePurchaseTotalsAction $recalculate,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(PurchaseInvoice $invoice, array $data): PurchaseInvoice
    {
        if (! $invoice->isDraft()) {
            throw new ApiException('DOCUMENT_POSTED', 'Posted purchase invoices cannot be edited.', 422);
        }

        return DB::transaction(function () use ($invoice, $data): PurchaseInvoice {
            $invoice = PurchaseInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== PurchaseInvoiceStatus::Draft) {
                throw new ApiException('DOCUMENT_POSTED', 'Posted purchase invoices cannot be edited.', 422);
            }

            if (array_key_exists('supplier_ulid', $data)) {
                $supplier = $this->catalog->supplier((string) $data['supplier_ulid']);
                if (! $supplier->is_active) {
                    throw ValidationException::withMessages([
                        'supplier_ulid' => 'Purchases cannot use an inactive supplier.',
                    ]);
                }
                $invoice->supplier_id = $supplier->id;
            }

            if (array_key_exists('warehouse_ulid', $data)) {
                $warehouse = $this->catalog->warehouse((string) $data['warehouse_ulid']);
                if ($warehouse->status !== WarehouseStatus::Active) {
                    throw ValidationException::withMessages([
                        'warehouse_ulid' => 'Purchases cannot use an inactive warehouse.',
                    ]);
                }
                $invoice->warehouse_id = $warehouse->id;
                $invoice->branch_id = $warehouse->branch_id;
            }

            foreach (['supplier_invoice_number', 'invoice_date', 'due_date', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $invoice->{$field} = $data[$field];
                }
            }

            if (array_key_exists('freight_amount', $data)) {
                $invoice->freight_amount = $this->money((string) $data['freight_amount']);
            }
            if (array_key_exists('other_charges', $data)) {
                $invoice->other_charges = $this->money((string) $data['other_charges']);
            }

            $invoice->updated_by = $this->tenantContext->userId();
            $invoice->save();
            $this->recalculate->execute($invoice);

            $this->audit->record('PURCHASE_UPDATED', [
                'resource_type' => 'purchase_invoice',
                'resource_ulid' => $invoice->ulid,
            ]);

            return $invoice->fresh(CreatePurchaseInvoiceAction::with()) ?? $invoice;
        });
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
