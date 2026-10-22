<?php

namespace App\Actions\Sales;

use App\Enums\ProductStatus;
use App\Enums\SaleLineKind;
use App\Enums\SaleStatus;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

class PrepareSaleReturnAmendmentAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RecalculateSaleReturnTotalsAction $recalculate,
    ) {}

    /**
     * @param array<string,mixed> $data
     * @return array{lines:list<array<string,mixed>>,totals:array{subtotal:string,discount_amount:string,tax_amount:string,grand_total:string}}
     */
    public function execute(SaleReturn $document, array $data): array
    {
        $invoiceMode = $document->sale_id !== null;
        $lines = [];

        foreach (($data['lines'] ?? []) as $index => $item) {
            $lines[] = $invoiceMode
                ? $this->invoiceLine($document, $item, $index)
                : $this->generalLine($item, $index);
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'A posted Sales Return amendment must contain at least one line.',
            ]);
        }

        return [
            'lines' => $lines,
            'totals' => $this->recalculate->calculateTotals($document, $lines),
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function invoiceLine(SaleReturn $document, array $item, int $index): array
    {
        if (! $document->sale || $document->sale->status !== SaleStatus::Posted) {
            throw ValidationException::withMessages([
                "lines.$index.sale_item_ulid" => 'Original sale is not available for returns.',
            ]);
        }

        $saleItem = SaleItem::query()
            ->with(['product.category', 'unit'])
            ->where('sale_id', $document->sale_id)
            ->where('ulid', (string) ($item['sale_item_ulid'] ?? ''))
            ->lockForUpdate()
            ->first();

        if (! $saleItem || ! $saleItem->product || ! $saleItem->unit) {
            throw ValidationException::withMessages([
                "lines.$index.sale_item_ulid" => 'Sale line was not found on the original invoice.',
            ]);
        }

        $quantity = $this->quantity((string) ($item['quantity'] ?? '0'), "lines.$index.quantity");
        $this->recalculate->assertReturnable($saleItem, $quantity, $document->id);
        $amounts = $this->recalculate->proportionalAmounts($saleItem, $quantity);

        return [
            'sale_item' => $saleItem,
            'product' => $saleItem->product,
            'unit' => $saleItem->unit,
            'line_kind' => $saleItem->line_kind->value,
            'barcode' => $saleItem->barcode,
            'quantity' => $quantity,
            'conversion_factor' => bcadd((string) $saleItem->conversion_factor, '0', 8),
            'stock_quantity' => $amounts['stock_quantity'],
            'unit_price' => bcadd((string) $saleItem->unit_price, '0', 4),
            'gross_amount' => $amounts['gross_amount'],
            'discount_percent' => bcadd((string) ($saleItem->discount_percent ?? '0'), '0', 8),
            'discount_amount' => $amounts['discount_amount'],
            'tax_percent' => bcadd((string) ($saleItem->tax_percent ?? '0'), '0', 8),
            'tax_amount' => $amounts['tax_amount'],
            'line_total' => $amounts['line_total'],
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
            ->with(['baseUnit', 'category'])
            ->where('ulid', (string) ($item['product_ulid'] ?? ''))
            ->where('is_active', true)
            ->where('status', ProductStatus::Active->value)
            ->first();

        if (! $product || ! $product->baseUnit || ! $product->baseUnit->is_active) {
            throw ValidationException::withMessages([
                "lines.$index.product_ulid" => 'The selected product or its base unit is unavailable.',
            ]);
        }

        $quantity = $this->quantity((string) ($item['quantity'] ?? '0'), "lines.$index.quantity");
        $unitPrice = $this->money((string) ($item['unit_price'] ?? '0'), "lines.$index.unit_price");
        $discountPercent = $this->percent((string) ($item['discount_percent'] ?? '0'), "lines.$index.discount_percent");

        $gross = bcmul($quantity, $unitPrice, 4);
        $discount = bcdiv(bcmul($gross, $discountPercent, 8), '100', 4);
        $taxable = bcsub($gross, $discount, 4);
        $taxPercent = $product->is_taxable
            ? bcadd((string) ($product->tax_percent ?? '0'), '0', 8)
            : '0.00000000';
        $tax = bcdiv(bcmul($taxable, $taxPercent, 8), '100', 4);

        return [
            'sale_item' => null,
            'product' => $product,
            'unit' => $product->baseUnit,
            'line_kind' => SaleLineKind::Sale->value,
            'barcode' => null,
            'quantity' => $quantity,
            'conversion_factor' => '1.00000000',
            'stock_quantity' => $quantity,
            'unit_price' => $unitPrice,
            'gross_amount' => $gross,
            'discount_percent' => $discountPercent,
            'discount_amount' => $discount,
            'tax_percent' => $taxPercent,
            'tax_amount' => $tax,
            'line_total' => bcadd($taxable, $tax, 4),
            'reason' => $item['reason'] ?? null,
            'notes' => $item['notes'] ?? null,
        ];
    }

    private function quantity(string $value, string $field): string
    {
        if (! preg_match('/^(?:0|[1-9]\\d*)(?:\\.\\d{1,6})?$/', $value)
            || bccomp($value, '0', 6) !== 1) {
            throw ValidationException::withMessages([$field => 'Return quantity must be greater than zero.']);
        }

        return bcadd($value, '0', 6);
    }

    private function money(string $value, string $field): string
    {
        if (! preg_match('/^(?:0|[1-9]\\d*)(?:\\.\\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages([$field => 'Amount must be a valid non-negative decimal.']);
        }

        return bcadd($value, '0', 4);
    }

    private function percent(string $value, string $field): string
    {
        if (! preg_match('/^(?:0|[1-9]\\d*)(?:\\.\\d{1,8})?$/', $value)
            || bccomp($value, '100', 8) === 1) {
            throw ValidationException::withMessages([$field => 'Percentage must be between 0 and 100.']);
        }

        return bcadd($value, '0', 8);
    }
}
