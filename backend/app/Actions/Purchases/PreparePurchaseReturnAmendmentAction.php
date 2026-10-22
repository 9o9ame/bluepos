<?php

namespace App\Actions\Purchases;

use App\Catalog\TenantCatalog;
use App\Enums\ProductStatus;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\WarehouseStatus;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\PurchaseReturn;
use App\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

class PreparePurchaseReturnAmendmentAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantCatalog $catalog,
        private readonly RecalculatePurchaseReturnTotalsAction $recalculate,
    ) {}

    /**
     * @param array<string,mixed> $data
     * @return array{lines:list<array<string,mixed>>,totals:array{subtotal:string,discount_amount:string,tax_amount:string,grand_total:string}}
     */
    public function execute(PurchaseReturn $document, array $data, array $before): array
    {
        $tenantId = $this->tenantContext->tenantId();
        $purchaseUlid = trim((string) ($data['purchase_ulid'] ?? $before['original_purchase']['ulid'] ?? ''));

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
                throw ValidationException::withMessages([
                    'purchase_ulid' => 'The selected posted purchase was not found in the active branch and warehouse.',
                ]);
            }

            if ($invoice->status !== PurchaseInvoiceStatus::Posted) {
                throw ValidationException::withMessages([
                    'purchase_ulid' => 'Purchase returns can only use posted purchase invoices.',
                ]);
            }

            if ($invoice->latestRevision()->exists()) {
                throw ValidationException::withMessages([
                    'purchase_ulid' => 'The selected purchase has amendment revisions and cannot be used as a return source yet.',
                ]);
            }

            $supplier = $invoice->supplier;
        } else {
            $supplierUlid = trim((string) ($data['supplier_ulid'] ?? $before['supplier']['ulid'] ?? ''));
            if ($supplierUlid === '') {
                throw ValidationException::withMessages([
                    'supplier_ulid' => 'Supplier is required for a general Purchase Return amendment.',
                ]);
            }

            $supplier = $this->catalog->supplier($supplierUlid);
            if (! $supplier->is_active) {
                throw ValidationException::withMessages([
                    'supplier_ulid' => 'Purchase returns cannot use an inactive supplier.',
                ]);
            }
        }

        $warehouseUlid = trim((string) ($data['warehouse_ulid'] ?? $before['warehouse']['ulid'] ?? ''));
        $warehouse = $this->catalog->warehouse($warehouseUlid);

        if ($warehouse->status !== WarehouseStatus::Active) {
            throw ValidationException::withMessages([
                'warehouse_ulid' => 'Purchase returns cannot use an inactive warehouse.',
            ]);
        }

        if ((int) $warehouse->branch_id !== $this->tenantContext->branchId()
            || (int) $warehouse->id !== $this->tenantContext->warehouseId()) {
            throw ValidationException::withMessages([
                'warehouse_ulid' => 'Switch to the selected branch and warehouse before amending this Purchase Return.',
            ]);
        }

        if ($invoice && (int) $invoice->warehouse_id !== (int) $warehouse->id) {
            throw ValidationException::withMessages([
                'warehouse_ulid' => 'The selected purchase belongs to a different warehouse.',
            ]);
        }

        $lines = [];

        foreach (($data['lines'] ?? []) as $index => $item) {
            $lines[] = $invoice
                ? $this->invoiceLine($document, $invoice, $item, $index)
                : $this->generalLine($item, $index);
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'A posted Purchase Return amendment must contain at least one line.',
            ]);
        }

        $subtotal = '0.0000';
        $discount = '0.0000';
        $tax = '0.0000';

        foreach ($lines as $line) {
            $subtotal = bcadd(
                $subtotal,
                bcmul($line['quantity'], $line['unit_cost'], 4),
                4,
            );
            $discount = bcadd($discount, $line['discount_amount'], 4);
            $tax = bcadd($tax, $line['tax_amount'], 4);
        }

        $grandTotal = bcadd(bcsub($subtotal, $discount, 4), $tax, 4);

        if (bccomp($grandTotal, '0', 4) < 0) {
            throw ValidationException::withMessages([
                'grand_total' => 'Purchase return grand total cannot be negative.',
            ]);
        }

        return [
            'header' => [
                'return_mode' => $invoice ? 'invoice' : 'general',
                'return_date' => (string) ($data['return_date'] ?? $before['return_date'] ?? now()->toDateString()),
                'supplier_reference' => $data['supplier_reference'] ?? ($before['supplier_reference'] ?? null),
                'reason' => $data['reason'] ?? ($before['reason'] ?? null),
                'notes' => $data['notes'] ?? ($before['notes'] ?? null),
                'original_purchase' => $invoice ? [
                    'ulid' => $invoice->ulid,
                    'document_number' => $invoice->document_number,
                    'supplier_invoice_number' => $invoice->supplier_invoice_number,
                ] : null,
                'supplier' => [
                    'ulid' => $supplier->ulid,
                    'code' => $supplier->code,
                    'name' => $supplier->name,
                ],
                'warehouse' => [
                    'ulid' => $warehouse->ulid,
                    'code' => $warehouse->code,
                    'name' => $warehouse->name,
                ],
            ],
            'lines' => $lines,
            'totals' => [
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'tax_amount' => $tax,
                'grand_total' => $grandTotal,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function invoiceLine(
        PurchaseReturn $document,
        PurchaseInvoice $invoice,
        array $item,
        int $index,
    ): array {
        $purchaseLine = PurchaseInvoiceLine::query()
            ->with(['product', 'unit'])
            ->where('purchase_invoice_id', $invoice->id)
            ->where('ulid', (string) ($item['purchase_line_ulid'] ?? ''))
            ->lockForUpdate()
            ->first();

        if (! $purchaseLine || ! $purchaseLine->product || ! $purchaseLine->unit) {
            throw ValidationException::withMessages([
                "lines.$index.purchase_line_ulid" => 'Purchase line was not found on the original invoice.',
            ]);
        }

        $quantity = $this->quantity((string) ($item['quantity'] ?? '0'), "lines.$index.quantity");
        $conversionFactor = bcadd((string) $purchaseLine->conversion_factor, '0', 8);
        $baseQuantity = bcmul($quantity, $conversionFactor, 6);

        $this->recalculate->assertReturnable(
            $purchaseLine,
            $baseQuantity,
            $document->id,
        );

        $originalQty = bcadd((string) $purchaseLine->quantity, '0', 6);
        if (bccomp($originalQty, '0', 6) !== 1) {
            throw ValidationException::withMessages([
                "lines.$index.quantity" => 'Original purchase quantity is invalid.',
            ]);
        }

        // Multiply first, then divide to avoid truncating a ratio before applying it.
        $discount = bcdiv(
            bcmul((string) $purchaseLine->discount_amount, $quantity, 10),
            $originalQty,
            4,
        );
        $tax = bcdiv(
            bcmul((string) $purchaseLine->tax_amount, $quantity, 10),
            $originalQty,
            4,
        );

        $unitCost = bcadd((string) $purchaseLine->unit_cost, '0', 4);
        $lineTotal = bcadd(
            bcsub(bcmul($quantity, $unitCost, 4), $discount, 4),
            $tax,
            4,
        );

        $batchNumber = $item['batch_number'] ?? $purchaseLine->batch_number;
        $expiryDate = $item['expiry_date'] ?? $purchaseLine->expiry_date?->toDateString();

        if ($purchaseLine->product->track_batch && (! is_string($batchNumber) || trim($batchNumber) === '')) {
            throw ValidationException::withMessages([
                "lines.$index.batch_number" => 'Batch number is required for this product.',
            ]);
        }

        if ($purchaseLine->product->track_expiry && (! is_string($expiryDate) || trim($expiryDate) === '')) {
            throw ValidationException::withMessages([
                "lines.$index.expiry_date" => 'Expiry date is required for this product.',
            ]);
        }

        return [
            'purchase_line' => $purchaseLine,
            'product' => $purchaseLine->product,
            'unit' => $purchaseLine->unit,
            'quantity' => $quantity,
            'conversion_factor' => $conversionFactor,
            'base_quantity' => $baseQuantity,
            'unit_cost' => $unitCost,
            'discount_amount' => $discount,
            'tax_amount' => $tax,
            'line_total' => $lineTotal,
            'batch_number' => $batchNumber,
            'expiry_date' => $expiryDate,
            'reason' => $item['reason'] ?? null,
            'notes' => $item['notes'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function generalLine(array $item, int $index): array
    {
        $product = Product::query()
            ->forTenant($this->tenantContext->tenantId())
            ->with('baseUnit')
            ->where('ulid', (string) ($item['product_ulid'] ?? ''))
            ->where('is_active', true)
            ->where('status', ProductStatus::Active->value)
            ->first();

        if (! $product || ! $product->baseUnit || ! $product->baseUnit->is_active) {
            throw ValidationException::withMessages([
                "lines.$index.product_ulid" => 'The selected product or its base unit is unavailable.',
            ]);
        }

        if ($product->track_batch || $product->track_expiry) {
            throw ValidationException::withMessages([
                "lines.$index.product_ulid" => 'Batch or expiry tracked products must be returned against a posted purchase invoice.',
            ]);
        }

        $quantity = $this->quantity((string) ($item['quantity'] ?? '0'), "lines.$index.quantity");
        $unitCost = $this->money((string) ($item['unit_cost'] ?? '0'), "lines.$index.unit_cost");
        $discount = $this->money((string) ($item['discount_amount'] ?? '0'), "lines.$index.discount_amount");
        $tax = $this->money((string) ($item['tax_amount'] ?? '0'), "lines.$index.tax_amount");
        $lineTotal = bcadd(
            bcsub(bcmul($quantity, $unitCost, 4), $discount, 4),
            $tax,
            4,
        );

        if (bccomp($lineTotal, '0', 4) < 0) {
            throw ValidationException::withMessages([
                "lines.$index.line_total" => 'Line total cannot be negative.',
            ]);
        }

        $batchNumber = $item['batch_number'] ?? null;
        $expiryDate = $item['expiry_date'] ?? null;

        if ($product->track_batch && (! is_string($batchNumber) || trim($batchNumber) === '')) {
            throw ValidationException::withMessages([
                "lines.$index.batch_number" => 'Batch number is required for this product.',
            ]);
        }

        if ($product->track_expiry && (! is_string($expiryDate) || trim($expiryDate) === '')) {
            throw ValidationException::withMessages([
                "lines.$index.expiry_date" => 'Expiry date is required for this product.',
            ]);
        }

        return [
            'purchase_line' => null,
            'product' => $product,
            'unit' => $product->baseUnit,
            'quantity' => $quantity,
            'conversion_factor' => '1.00000000',
            'base_quantity' => $quantity,
            'unit_cost' => $unitCost,
            'discount_amount' => $discount,
            'tax_amount' => $tax,
            'line_total' => $lineTotal,
            'batch_number' => $batchNumber,
            'expiry_date' => $expiryDate,
            'reason' => $item['reason'] ?? null,
            'notes' => $item['notes'] ?? null,
        ];
    }

    private function quantity(string $value, string $field): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/', $value)
            || bccomp($value, '0', 6) !== 1) {
            throw ValidationException::withMessages([$field => 'Return quantity must be greater than zero.']);
        }

        return bcadd($value, '0', 6);
    }

    private function money(string $value, string $field): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/', $value)
            || bccomp($value, '0', 4) < 0) {
            throw ValidationException::withMessages([$field => 'Amount must be a valid non-negative decimal.']);
        }

        return bcadd($value, '0', 4);
    }
}
