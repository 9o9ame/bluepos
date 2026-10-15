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

        $lineDiscount = $discount;
        $invoiceDiscount = $this->proportionalInvoiceDiscount(
            $document,
            $subtotal,
            $lineDiscount,
            $tax,
        );
        $totalDiscount = bcadd($lineDiscount, $invoiceDiscount, 4);

        $document->subtotal = $subtotal;
        $document->discount_amount = $totalDiscount;
        $document->tax_amount = $tax;
        $document->grand_total = bcadd(
            bcsub($subtotal, $totalDiscount, 4),
            $tax,
            4,
        );
        $document->refund_amount = '0.0000';

        if (bccomp((string) $document->grand_total, '0', 4) === -1) {
            throw ValidationException::withMessages([
                'grand_total' => 'Sales return total cannot be negative.',
            ]);
        }

        $document->save();

        return $document;
    }

    private function proportionalInvoiceDiscount(
        SaleReturn $document,
        string $subtotal,
        string $lineDiscount,
        string $tax,
    ): string {
        $sale = $document->sale()->first();

        if (! $sale) {
            return '0.0000';
        }

        $originalInvoiceDiscount = bcadd(
            (string) ($sale->invoice_discount_amount ?? '0'),
            '0',
            4,
        );

        if (bccomp($originalInvoiceDiscount, '0', 4) !== 1) {
            return '0.0000';
        }

        $originalBeforeInvoiceDiscount = bcadd(
            (string) $sale->grand_total,
            $originalInvoiceDiscount,
            4,
        );
        $currentBeforeInvoiceDiscount = bcadd(
            bcsub($subtotal, $lineDiscount, 4),
            $tax,
            4,
        );

        if (
            bccomp($originalBeforeInvoiceDiscount, '0', 4) !== 1
            || bccomp($currentBeforeInvoiceDiscount, '0', 4) !== 1
        ) {
            return '0.0000';
        }

        $allocated = '0.0000';
        $returnedBeforeInvoiceDiscount = '0.0000';

        $postedReturns = SaleReturn::query()
            ->where('sale_id', $sale->id)
            ->where('status', SaleReturnStatus::Posted->value)
            ->when(
                $document->exists,
                fn ($query) => $query->whereKeyNot($document->id),
            )
            ->get();

        foreach ($postedReturns as $postedReturn) {
            $postedLineDiscount = bcadd(
                (string) SaleReturnLine::query()
                    ->where('sale_return_id', $postedReturn->id)
                    ->sum('discount_amount'),
                '0',
                4,
            );

            $postedInvoiceDiscount = bcsub(
                (string) $postedReturn->discount_amount,
                $postedLineDiscount,
                4,
            );
            if (bccomp($postedInvoiceDiscount, '0', 4) === -1) {
                $postedInvoiceDiscount = '0.0000';
            }

            $allocated = bcadd($allocated, $postedInvoiceDiscount, 4);
            $returnedBeforeInvoiceDiscount = bcadd(
                $returnedBeforeInvoiceDiscount,
                bcadd(
                    bcsub(
                        (string) $postedReturn->subtotal,
                        $postedLineDiscount,
                        4,
                    ),
                    (string) $postedReturn->tax_amount,
                    4,
                ),
                4,
            );
        }

        $remaining = bcsub($originalInvoiceDiscount, $allocated, 4);
        if (bccomp($remaining, '0', 4) !== 1) {
            return '0.0000';
        }

        $returnedAfterCurrent = bcadd(
            $returnedBeforeInvoiceDiscount,
            $currentBeforeInvoiceDiscount,
            4,
        );

        if (
            bccomp(
                $returnedAfterCurrent,
                $originalBeforeInvoiceDiscount,
                4,
            ) >= 0
        ) {
            return $remaining;
        }

        $proportional = bcdiv(
            bcmul(
                $originalInvoiceDiscount,
                $currentBeforeInvoiceDiscount,
                8,
            ),
            $originalBeforeInvoiceDiscount,
            4,
        );

        return bccomp($proportional, $remaining, 4) === 1
            ? $remaining
            : $proportional;
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
