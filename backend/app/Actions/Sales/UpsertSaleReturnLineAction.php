<?php

namespace App\Actions\Sales;

use App\Enums\SaleReturnStatus;
use App\Enums\SaleStatus;
use App\Exceptions\ApiException;
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
     * @param array{sale_item_ulid:string,quantity:string,reason?:string|null,notes?:string|null} $data
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

            if (! $document->sale || $document->sale->status !== SaleStatus::Posted) {
                throw ValidationException::withMessages([
                    'sale_item_ulid' => 'Original sale is not available for returns.',
                ]);
            }

            $saleItem = SaleItem::query()
                ->with(['product', 'unit'])
                ->where('sale_id', $document->sale_id)
                ->where('ulid', $data['sale_item_ulid'])
                ->lockForUpdate()
                ->first();

            if (! $saleItem) {
                throw ValidationException::withMessages([
                    'sale_item_ulid' => 'Sale line was not found on the original invoice.',
                ]);
            }

            if ($line === null) {
                $exists = SaleReturnLine::query()
                    ->where('sale_return_id', $document->id)
                    ->where('sale_item_id', $saleItem->id)
                    ->exists();

                if ($exists) {
                    throw ValidationException::withMessages([
                        'sale_item_ulid' => 'This sale line is already on the return.',
                    ]);
                }
            }

            $quantity = bcadd((string) $data['quantity'], '0', 6);
            $this->recalculate->assertReturnable($saleItem, $quantity, $document->id);
            $amounts = $this->recalculate->proportionalAmounts($saleItem, $quantity);

            $payload = [
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
                'discount_amount' => $amounts['discount_amount'],
                'tax_amount' => $amounts['tax_amount'],
                'line_total' => $amounts['line_total'],
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
            ];

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
            ]);

            return $line->fresh(['product.category', 'unit', 'saleItem']) ?? $line;
        });
    }
}
