<?php

namespace App\Actions\Sales;

use App\Enums\ProductStatus;
use App\Enums\SaleLineKind;
use App\Enums\SaleReturnStatus;
use App\Enums\SaleStatus;
use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnLine;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpsertSaleReturnLineAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RecalculateSaleReturnTotalsAction $recalculate,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array<string,mixed> $data
     */
    public function execute(
        SaleReturn $document,
        array $data,
        ?SaleReturnLine $line = null,
    ): SaleReturnLine {
        if (! $document->isDraft()) {
            throw new ApiException('DOCUMENT_POSTED', 'Posted sales returns cannot be edited.', 422);
        }

        return DB::transaction(function () use ($document, $data, $line): SaleReturnLine {
            $document = SaleReturn::query()
                ->with('sale')
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->status !== SaleReturnStatus::Draft) {
                throw new ApiException('DOCUMENT_POSTED', 'Posted sales returns cannot be edited.', 422);
            }

            $payload = $document->sale_id !== null
                ? $this->invoicePayload($document, $data, $line)
                : $this->generalPayload($document, $data, $line);

            if ($line) {
                $line = SaleReturnLine::query()
                    ->whereKey($line->id)
                    ->where('sale_return_id', $document->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $line->fill($payload);
                $line->save();
                $event = 'SALE_RETURN_LINE_UPDATED';
            } else {
                $line = SaleReturnLine::query()->create([
                    'tenant_id' => $this->tenantContext->tenantId(),
                    'sale_return_id' => $document->id,
                    ...$payload,
                ]);
                $event = 'SALE_RETURN_LINE_ADDED';
            }

            $document->updated_by = $this->tenantContext->userId();
            $document->save();
            $this->recalculate->execute($document);

            $this->audit->record($event, [
                'resource_type' => 'sale_return',
                'resource_ulid' => $document->ulid,
                'line_ulid' => $line->ulid,
                'return_mode' => $document->sale_id === null ? 'general' : 'invoice',
            ]);

            return $line->fresh(['product.category', 'unit', 'saleItem']) ?? $line;
        });
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function invoicePayload(
        SaleReturn $document,
        array $data,
        ?SaleReturnLine $line,
    ): array {
        if (! $document->sale || $document->sale->status !== SaleStatus::Posted) {
            throw ValidationException::withMessages([
                'sale_item_ulid' => 'Original sale is not available for returns.',
            ]);
        }

        $saleItemUlid = trim((string) ($data['sale_item_ulid'] ?? $line?->saleItem?->ulid ?? ''));
        $saleItem = SaleItem::query()
            ->with(['product', 'unit'])
            ->where('sale_id', $document->sale_id)
            ->where('ulid', $saleItemUlid)
            ->lockForUpdate()
            ->first();

        if (! $saleItem) {
            throw ValidationException::withMessages([
                'sale_item_ulid' => 'Sale line was not found on the original invoice.',
            ]);
        }

        $duplicateSaleItem = SaleReturnLine::query()
            ->where('sale_return_id', $document->id)
            ->where('sale_item_id', $saleItem->id)
            ->when($line !== null, fn ($query) => $query->where('id', '!=', $line->id))
            ->exists();

        if ($duplicateSaleItem) {
            throw ValidationException::withMessages([
                'sale_item_ulid' => 'This sale line is already on the return.',
            ]);
        }

        $quantity = $this->normalizeQuantity((string) ($data['quantity'] ?? '0'));
        $this->recalculate->assertReturnable($saleItem, $quantity, $document->id);
        $amounts = $this->recalculate->proportionalAmounts($saleItem, $quantity);

        return [
            'sale_item_id' => $saleItem->id,
            'product_id' => $saleItem->product_id,
            'unit_id' => $saleItem->unit_id,
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
            'reason' => $data['reason'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function generalPayload(
        SaleReturn $document,
        array $data,
        ?SaleReturnLine $line,
    ): array {
        $productUlid = trim((string) ($data['product_ulid'] ?? $line?->product?->ulid ?? ''));

        $product = Product::query()
            ->forTenant($this->tenantContext->tenantId())
            ->with('baseUnit')
            ->where('ulid', $productUlid)
            ->where('is_active', true)
            ->where('status', ProductStatus::Active->value)
            ->first();

        if (! $product || ! $product->baseUnit || ! $product->baseUnit->is_active) {
            throw ValidationException::withMessages([
                'product_ulid' => 'The selected product or its base unit is unavailable.',
            ]);
        }

        $duplicateProduct = SaleReturnLine::query()
            ->where('sale_return_id', $document->id)
            ->whereNull('sale_item_id')
            ->where('product_id', $product->id)
            ->when($line !== null, fn ($query) => $query->where('id', '!=', $line->id))
            ->exists();

        if ($duplicateProduct) {
            throw ValidationException::withMessages([
                'product_ulid' => 'This product is already on the general return.',
            ]);
        }

        $quantity = $this->normalizeQuantity((string) ($data['quantity'] ?? '0'));
        if (! $product->baseUnit->allows_decimal && str_contains($quantity, '.') && rtrim(substr(strstr($quantity, '.') ?: '', 1), '0') !== '') {
            throw ValidationException::withMessages([
                'quantity' => 'The selected product unit does not allow decimal quantities.',
            ]);
        }

        $unitPrice = $this->normalizeMoney((string) ($data['unit_price'] ?? '0'), 'unit_price');
        $discountPercent = $this->normalizePercent((string) ($data['discount_percent'] ?? '0'));
        $grossAmount = bcmul($quantity, $unitPrice, 4);
        $discountAmount = bcdiv(bcmul($grossAmount, $discountPercent, 8), '100', 4);
        $taxableAmount = bcsub($grossAmount, $discountAmount, 4);
        $taxPercent = $product->is_taxable
            ? bcadd((string) ($product->tax_percent ?? '0'), '0', 8)
            : '0.00000000';
        $taxAmount = bcdiv(bcmul($taxableAmount, $taxPercent, 8), '100', 4);
        $lineTotal = bcadd($taxableAmount, $taxAmount, 4);

        return [
            'sale_item_id' => null,
            'product_id' => $product->id,
            'unit_id' => $product->base_unit_id,
            'line_kind' => SaleLineKind::Sale->value,
            'barcode' => null,
            'quantity' => $quantity,
            'conversion_factor' => '1.00000000',
            'stock_quantity' => $quantity,
            'unit_price' => $unitPrice,
            'gross_amount' => $grossAmount,
            'discount_percent' => $discountPercent,
            'discount_amount' => $discountAmount,
            'tax_percent' => $taxPercent,
            'tax_amount' => $taxAmount,
            'line_total' => $lineTotal,
            'reason' => $data['reason'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function normalizeQuantity(string $value): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/', $value)) {
            throw ValidationException::withMessages([
                'quantity' => 'Quantity must be a valid non-negative decimal with up to 6 places.',
            ]);
        }

        $normalized = bcadd($value, '0', 6);
        if (bccomp($normalized, '0', 6) !== 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Return quantity must be greater than zero.',
            ]);
        }

        return $normalized;
    }

    private function normalizeMoney(string $value, string $field): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages([
                $field => 'Price must be a valid non-negative decimal with up to 4 places.',
            ]);
        }

        return bcadd($value, '0', 4);
    }

    private function normalizePercent(string $value): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,8})?$/', $value)) {
            throw ValidationException::withMessages([
                'discount_percent' => 'Discount percentage must be a valid non-negative decimal with up to 8 places.',
            ]);
        }

        $normalized = bcadd($value, '0', 8);
        if (bccomp($normalized, '100', 8) === 1) {
            throw ValidationException::withMessages([
                'discount_percent' => 'Discount percentage cannot exceed 100.',
            ]);
        }

        return $normalized;
    }
}
