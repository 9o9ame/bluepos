<?php

namespace App\Actions\Sales;

use App\Enums\SaleReturnStatus;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnLine;
use Illuminate\Validation\ValidationException;

class RecalculateSaleReturnTotalsAction
{
    public function execute(SaleReturn $document): SaleReturn
    {
        $lines = SaleReturnLine::query()
            ->where('sale_return_id', $document->id)
            ->get();

        $subtotal = '0.0000';
        $discount = '0.0000';
        $tax = '0.0000';

        foreach ($lines as $line) {
            $subtotal = bcadd($subtotal, (string) $line->gross_amount, 4);
            $discount = bcadd($discount, (string) $line->discount_amount, 4);
            $tax = bcadd($tax, (string) $line->tax_amount, 4);
        }

        $document->subtotal = $subtotal;
        $document->discount_amount = $discount;
        $document->tax_amount = $tax;
        $document->grand_total = bcadd(bcsub($subtotal, $discount, 4), $tax, 4);
        $document->refund_amount = '0.0000';

        if (bccomp((string) $document->grand_total, '0', 4) === -1) {
            throw ValidationException::withMessages([
                'grand_total' => 'Sales return total cannot be negative.',
            ]);
        }

        $document->save();

        return $document;
    }

    /**
     * @return array{stock_quantity:string,gross_amount:string,discount_amount:string,tax_amount:string,line_total:string}
     */
    public function proportionalAmounts(SaleItem $saleItem, string $returnQuantity): array
    {
        if (bccomp($returnQuantity, '0', 6) !== 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Return quantity must be greater than zero.',
            ]);
        }

        $originalQty = (string) $saleItem->quantity;
        if (bccomp($originalQty, '0', 6) !== 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Original sale quantity is invalid.',
            ]);
        }

        $ratio = bcdiv($returnQuantity, $originalQty, 12);

        return [
            'stock_quantity' => bcmul((string) $saleItem->stock_quantity, $ratio, 6),
            'gross_amount' => bcmul((string) $saleItem->gross_amount, $ratio, 4),
            'discount_amount' => bcmul((string) $saleItem->discount_amount, $ratio, 4),
            'tax_amount' => bcmul((string) $saleItem->tax_amount, $ratio, 4),
            'line_total' => bcmul((string) $saleItem->line_total, $ratio, 4),
        ];
    }

    public function remainingReturnableQuantity(
        SaleItem $saleItem,
        ?int $excludeReturnId = null,
    ): string {
        $query = SaleReturnLine::query()
            ->where('sale_item_id', $saleItem->id)
            ->whereHas('saleReturn', function ($q) use ($excludeReturnId): void {
                $q->where('status', SaleReturnStatus::Posted->value);
                if ($excludeReturnId !== null) {
                    $q->where('id', '!=', $excludeReturnId);
                }
            });

        $returned = bcadd((string) ($query->sum('quantity') ?: '0'), '0', 6);
        $remaining = bcsub((string) $saleItem->quantity, $returned, 6);

        return bccomp($remaining, '0', 6) === -1 ? '0.000000' : $remaining;
    }

    public function assertReturnable(
        SaleItem $saleItem,
        string $quantity,
        ?int $excludeReturnId = null,
    ): void {
        $remaining = $this->remainingReturnableQuantity($saleItem, $excludeReturnId);

        if (bccomp($quantity, $remaining, 6) === 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Return quantity exceeds the remaining returnable quantity for this sale line.',
            ]);
        }
    }
}
