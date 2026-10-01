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
     * @param  array<string, mixed>  $data
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
            $amounts = $this->recalculate->resolveLineMoney(
                $quantity,
                $conversion,
                $unitCost,
                (string) ($data['trade_disc_pct'] ?? '0'),
                (string) ($data['regular_disc_pct'] ?? '0'),
                (string) ($data['special_disc_pct'] ?? '0'),
                (string) ($data['tax_pct'] ?? '0'),
                (string) ($data['further_tax_pct'] ?? '0'),
                isset($data['discount_amount']) ? (string) $data['discount_amount'] : '0',
                isset($data['tax_amount']) ? (string) $data['tax_amount'] : '0',
            );

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

            $qtyCtn = $this->qty((string) ($data['qty_ctn'] ?? '0'), 'qty_ctn');
            $freePcs = $this->qty((string) ($data['free_pcs'] ?? '0'), 'free_pcs', allowZero: true);
            $mrp = $this->money((string) ($data['mrp'] ?? '0'));

            $payload = [
                'product_id' => $product->id,
                'unit_id' => $unit->id,
                'quantity' => bcadd($quantity, '0', 6),
                'conversion_factor' => bcadd($conversion, '0', 8),
                'base_quantity' => $amounts['base_quantity'],
                'unit_cost' => bcadd($unitCost, '0', 4),
                'discount_amount' => $amounts['discount_amount'],
                'tax_amount' => $amounts['tax_amount'],
                'further_tax_amount' => $amounts['further_tax_amount'],
                'line_total' => $amounts['line_total'],
                'supplier_product_code' => $data['supplier_product_code'] ?? null,
                'batch_number' => is_string($batch) ? trim($batch) : null,
                'expiry_date' => $expiry,
                'notes' => $data['notes'] ?? null,
                'brand_label' => $data['brand_label'] ?? null,
                'hs_code' => $data['hs_code'] ?? null,
                'pack_size' => $data['pack_size'] ?? null,
                'qty_ctn' => $qtyCtn,
                'free_pcs' => $freePcs,
                'price_type' => $data['price_type'] ?? 'trade',
                'mrp' => $mrp,
                'trade_disc_pct' => bcadd((string) ($data['trade_disc_pct'] ?? '0'), '0', 8),
                'regular_disc_pct' => bcadd((string) ($data['regular_disc_pct'] ?? '0'), '0', 8),
                'special_disc_pct' => bcadd((string) ($data['special_disc_pct'] ?? '0'), '0', 8),
                'tax_pct' => bcadd((string) ($data['tax_pct'] ?? '0'), '0', 8),
                'further_tax_pct' => bcadd((string) ($data['further_tax_pct'] ?? '0'), '0', 8),
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

    private function money(string $value): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages([
                'mrp' => 'Amount must be a valid non-negative decimal.',
            ]);
        }

        return bcadd($value, '0', 4);
    }

    private function qty(string $value, string $field, bool $allowZero = false): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/', $value)) {
            throw ValidationException::withMessages([
                $field => 'Quantity must be a valid non-negative decimal.',
            ]);
        }
        if (! $allowZero && bccomp($value, '0', 6) === -1) {
            throw ValidationException::withMessages([
                $field => 'Quantity cannot be negative.',
            ]);
        }

        return bcadd($value, '0', 6);
    }
}
