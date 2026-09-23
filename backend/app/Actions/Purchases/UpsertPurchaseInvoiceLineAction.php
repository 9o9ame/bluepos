<?php

namespace App\Actions\Purchases;

use App\Catalog\TenantCatalog;
use App\Enums\ProductStatus;
use App\Enums\PurchaseInvoiceStatus;
use App\Exceptions\ApiException;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpsertPurchaseInvoiceLineAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantCatalog $catalog,
        private readonly RecalculatePurchaseTotalsAction $recalculate,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{
     *     product_ulid: string,
     *     unit_ulid: string,
     *     quantity: string,
     *     conversion_factor?: string,
     *     unit_cost: string,
     *     discount_amount?: string,
     *     tax_amount?: string,
     *     supplier_product_code?: string|null,
     *     batch_number?: string|null,
     *     expiry_date?: string|null,
     *     notes?: string|null
     * }  $data
     */
    public function execute(
        PurchaseInvoice $invoice,
        array $data,
        ?PurchaseInvoiceLine $line = null,
    ): PurchaseInvoiceLine {
        if (! $invoice->isDraft()) {
            throw new ApiException('DOCUMENT_POSTED', 'Posted purchase invoices cannot be edited.', 422);
        }

        return DB::transaction(function () use ($invoice, $data, $line): PurchaseInvoiceLine {
            $invoice = PurchaseInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== PurchaseInvoiceStatus::Draft) {
                throw new ApiException('DOCUMENT_POSTED', 'Posted purchase invoices cannot be edited.', 422);
            }

            $product = $this->catalog->product($data['product_ulid']);
            if ($product->status !== ProductStatus::Active || ! $product->is_active) {
                throw ValidationException::withMessages([
                    'product_ulid' => 'Purchases cannot use an inactive product.',
                ]);
            }

            $unit = $this->catalog->unit($data['unit_ulid']);
            $conversion = array_key_exists('conversion_factor', $data)
                ? (string) $data['conversion_factor']
                : '1.00000000';
            if ((int) $unit->id === (int) $product->base_unit_id && ! array_key_exists('conversion_factor', $data)) {
                $conversion = '1.00000000';
            }

            $quantity = (string) $data['quantity'];
            $unitCost = (string) $data['unit_cost'];
            $discount = (string) ($data['discount_amount'] ?? '0');
            $tax = (string) ($data['tax_amount'] ?? '0');
            $amounts = $this->recalculate->lineAmounts($quantity, $conversion, $unitCost, $discount, $tax);

            $batch = $data['batch_number'] ?? null;
            $expiry = $data['expiry_date'] ?? null;
            if ($product->track_batch && (! is_string($batch) || trim($batch) === '')) {
                throw ValidationException::withMessages([
                    'batch_number' => 'Batch number is required for this product.',
                ]);
            }
            if ($product->track_expiry && (! is_string($expiry) || trim($expiry) === '')) {
                throw ValidationException::withMessages([
                    'expiry_date' => 'Expiry date is required for this product.',
                ]);
            }

            $payload = [
                'product_id' => $product->id,
                'unit_id' => $unit->id,
                'quantity' => bcadd($quantity, '0', 6),
                'conversion_factor' => bcadd($conversion, '0', 8),
                'base_quantity' => $amounts['base_quantity'],
                'unit_cost' => bcadd($unitCost, '0', 4),
                'discount_amount' => bcadd($discount, '0', 4),
                'tax_amount' => bcadd($tax, '0', 4),
                'line_total' => $amounts['line_total'],
                'supplier_product_code' => $data['supplier_product_code'] ?? null,
                'batch_number' => is_string($batch) ? trim($batch) : null,
                'expiry_date' => $expiry,
                'notes' => $data['notes'] ?? null,
            ];

            if ($line) {
                $line = PurchaseInvoiceLine::query()
                    ->whereKey($line->id)
                    ->where('purchase_invoice_id', $invoice->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $line->fill($payload);
                $line->save();
                $event = 'PURCHASE_LINE_UPDATED';
            } else {
                $line = PurchaseInvoiceLine::query()->create([
                    'tenant_id' => $this->tenantContext->tenantId(),
                    'purchase_invoice_id' => $invoice->id,
                    ...$payload,
                ]);
                $event = 'PURCHASE_LINE_ADDED';
            }

            $invoice->updated_by = $this->tenantContext->userId();
            $invoice->save();
            $this->recalculate->execute($invoice);

            $this->audit->record($event, [
                'resource_type' => 'purchase_invoice',
                'resource_ulid' => $invoice->ulid,
                'line_ulid' => $line->ulid,
            ]);

            return $line->fresh(['product', 'unit']) ?? $line;
        });
    }
}
