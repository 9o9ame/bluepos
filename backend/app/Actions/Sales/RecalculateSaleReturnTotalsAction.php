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

        $totals = $this->calculateTotals($document, $lines->all());

        $document->subtotal = $totals['subtotal'];
        $document->discount_amount = $totals['discount_amount'];
        $document->tax_amount = $totals['tax_amount'];
        $document->grand_total = $totals['grand_total'];
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
     * @param list<object|array<string,mixed>> $lines
     * @return array{subtotal:string,discount_amount:string,tax_amount:string,grand_total:string}
     */
    public function calculateTotals(SaleReturn $document, array $lines): array
    {
        $subtotal = '0.0000';
        $lineDiscount = '0.0000';
        $tax = '0.0000';

        foreach ($lines as $line) {
            $gross = is_array($line) ? ($line['gross_amount'] ?? '0') : $line->gross_amount;
            $discount = is_array($line) ? ($line['discount_amount'] ?? '0') : $line->discount_amount;
            $lineTax = is_array($line) ? ($line['tax_amount'] ?? '0') : $line->tax_amount;

            $subtotal = bcadd($subtotal, (string) $gross, 4);
            $lineDiscount = bcadd($lineDiscount, (string) $discount, 4);
            $tax = bcadd($tax, (string) $lineTax, 4);
        }

        $invoiceDiscount = $this->proportionalInvoiceDiscount(
            $document,
            $subtotal,
            $lineDiscount,
            $tax,
        );
        $totalDiscount = bcadd($lineDiscount, $invoiceDiscount, 4);
        $grandTotal = bcadd(bcsub($subtotal, $totalDiscount, 4), $tax, 4);

        if (bccomp($grandTotal, '0', 4) < 0) {
            throw ValidationException::withMessages([
                'grand_total' => 'Sales return total cannot be negative.',
            ]);
        }

        return [
            'subtotal' => $subtotal,
            'discount_amount' => $totalDiscount,
            'tax_amount' => $tax,
            'grand_total' => $grandTotal,
        ];
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
                fn ($query) => $query->where('id', '!=', $document->id),
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

        return [
            'stock_quantity' => bcdiv(
                bcmul((string) $saleItem->stock_quantity, $returnQuantity, 12),
                $originalQty,
                6,
            ),
            'gross_amount' => bcdiv(
                bcmul((string) $saleItem->gross_amount, $returnQuantity, 10),
                $originalQty,
                4,
            ),
            'discount_amount' => bcdiv(
                bcmul((string) $saleItem->discount_amount, $returnQuantity, 10),
                $originalQty,
                4,
            ),
            'tax_amount' => bcdiv(
                bcmul((string) $saleItem->tax_amount, $returnQuantity, 10),
                $originalQty,
                4,
            ),
            'line_total' => bcdiv(
                bcmul((string) $saleItem->line_total, $returnQuantity, 10),
                $originalQty,
                4,
            ),
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
