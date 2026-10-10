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
                if (
                    $invoice->purchase_order_id !== null
                    && (int) $invoice->purchaseOrder()->value('supplier_id') !== (int) $supplier->id
                ) {
                    throw ValidationException::withMessages([
                        'supplier_ulid' => 'Purchase Invoice supplier must match the linked Purchase Order.',
                    ]);
                }

                $invoice->supplier_id = $supplier->id;
            }

            if (array_key_exists('warehouse_ulid', $data)) {
                $requestedWarehouse = $this->catalog->warehouse((string) $data['warehouse_ulid']);
                $warehouse = $this->tenantContext->warehouse();
                if ((int) $requestedWarehouse->id !== (int) $warehouse->id) {
                    throw ValidationException::withMessages([
                        'warehouse_ulid' => 'Purchase warehouse must match the active warehouse context.',
                    ]);
                }
                if ($warehouse->status !== WarehouseStatus::Active) {
                    throw ValidationException::withMessages([
                        'warehouse_ulid' => 'Purchases cannot use an inactive warehouse.',
                    ]);
                }
                $invoice->warehouse_id = $warehouse->id;
                $invoice->branch_id = $warehouse->branch_id;
            }

            foreach ([
                'supplier_invoice_number',
                'invoice_type',
                'currency_code',
                'calculation_method',
                'default_price_type',
                'brand_label',
                'invoice_date',
                'due_date',
                'notes',
                'tax_type',
                'payment_terms',
            ] as $field) {
                if (array_key_exists($field, $data)) {
                    $invoice->{$field} = $data[$field];
                }
            }

            if ($invoice->purchase_order_id !== null) {
                $invoice->loadMissing('purchaseOrder');
                $invoice->po_number = $invoice->purchaseOrder?->document_number;
            } elseif (array_key_exists('po_number', $data)) {
                $invoice->po_number = $data['po_number'];
            }

            foreach ([
                'default_sales_tax_pct',
                'default_further_tax_pct',
                'default_advance_tax_pct',
            ] as $field) {
                if (array_key_exists($field, $data)) {
                    $invoice->{$field} = $this->pct((string) $data[$field]);
                }
            }

            foreach ([
                'freight_amount',
                'loading_amount',
                'other_charges',
                'other_discount',
                'trade_offer',
                'advance_tax_amount',
            ] as $field) {
                if (array_key_exists($field, $data)) {
                    $invoice->{$field} = $this->money((string) $data[$field]);
                }
            }

            if (array_key_exists('round_off', $data)) {
                $invoice->round_off = $this->signedMoney((string) $data['round_off']);
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

    private function signedMoney(string $value): string
    {
        if (! preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages([
                'round_off' => 'Round off must be a valid decimal.',
            ]);
        }

        return bcadd($value, '0', 4);
    }

    private function pct(string $value): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,8})?$/', $value)) {
            throw ValidationException::withMessages([
                'percent' => 'Percentage must be a valid non-negative decimal.',
            ]);
        }
        if (bccomp($value, '100', 8) === 1) {
            throw ValidationException::withMessages([
                'percent' => 'Percentage cannot exceed 100.',
            ]);
        }

        return bcadd($value, '0', 8);
    }
}
