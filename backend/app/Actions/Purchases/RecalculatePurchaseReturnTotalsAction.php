<?php

namespace App\Actions\Purchases;

use App\Enums\PurchaseReturnStatus;
use App\Models\PurchaseInvoiceLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecalculatePurchaseReturnTotalsAction
{
    public function execute(PurchaseReturn $document): PurchaseReturn
    {
        $lines = PurchaseReturnLine::query()
            ->where('purchase_return_id', $document->id)
            ->get();

        $subtotal = '0.0000';
        $discount = '0.0000';
        $tax = '0.0000';

        foreach ($lines as $line) {
            $subtotal = bcadd($subtotal, bcmul((string) $line->quantity, (string) $line->unit_cost, 4), 4);
            $discount = bcadd($discount, (string) $line->discount_amount, 4);
            $tax = bcadd($tax, (string) $line->tax_amount, 4);
        }

        $document->subtotal = $subtotal;
        $document->discount_amount = $discount;
        $document->tax_amount = $tax;
        $document->grand_total = bcadd(bcsub($subtotal, $discount, 4), $tax, 4);

        if (bccomp((string) $document->grand_total, '0', 4) === -1) {
            throw ValidationException::withMessages([
                'grand_total' => 'Purchase return grand total cannot be negative.',
            ]);
        }

        $document->save();

        return $document;
    }

    /**
     * @return array{base_quantity: string, line_total: string, discount_amount: string, tax_amount: string}
     */
    public function lineAmounts(
        string $quantity,
        string $conversionFactor,
        string $unitCost,
        string $discount,
        string $tax,
    ): array {
        if (bccomp($quantity, '0', 6) !== 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Quantity must be greater than zero.',
            ]);
        }
        if (bccomp($conversionFactor, '0', 8) !== 1) {
            throw ValidationException::withMessages([
                'conversion_factor' => 'Conversion factor must be greater than zero.',
            ]);
        }
        if (bccomp($unitCost, '0', 4) === -1) {
            throw ValidationException::withMessages([
                'unit_cost' => 'Unit cost cannot be negative.',
            ]);
        }
        if (bccomp($discount, '0', 4) === -1) {
            throw ValidationException::withMessages([
                'discount_amount' => 'Discount cannot be negative.',
            ]);
        }
        if (bccomp($tax, '0', 4) === -1) {
            throw ValidationException::withMessages([
                'tax_amount' => 'Tax cannot be negative.',
            ]);
        }

        $baseQuantity = bcmul($quantity, $conversionFactor, 6);
        $lineTotal = bcadd(bcsub(bcmul($quantity, $unitCost, 4), $discount, 4), $tax, 4);

        if (bccomp($lineTotal, '0', 4) === -1) {
            throw ValidationException::withMessages([
                'line_total' => 'Line total cannot be negative.',
            ]);
        }

        return [
            'base_quantity' => $baseQuantity,
            'line_total' => $lineTotal,
            'discount_amount' => bcadd($discount, '0', 4),
            'tax_amount' => bcadd($tax, '0', 4),
        ];
    }

    /**
     * Proportional discount/tax from the original purchase line for a return quantity.
     *
     * @return array{discount_amount: string, tax_amount: string}
     */
    public function proportionalCharges(PurchaseInvoiceLine $purchaseLine, string $returnQuantity): array
    {
        $originalQty = (string) $purchaseLine->quantity;
        if (bccomp($originalQty, '0', 6) !== 1) {
            return ['discount_amount' => '0.0000', 'tax_amount' => '0.0000'];
        }

        $ratio = bcdiv($returnQuantity, $originalQty, 8);

        return [
            'discount_amount' => bcmul((string) $purchaseLine->discount_amount, $ratio, 4),
            'tax_amount' => bcmul((string) $purchaseLine->tax_amount, $ratio, 4),
        ];
    }

    public function inventoryUnitCost(string $unitCost, string $conversionFactor): string
    {
        return bcdiv($unitCost, $conversionFactor, 4);
    }

    /**
     * Remaining returnable base quantity for a purchase line (posted returns only).
     * Caller should lock the purchase invoice line first when posting.
     */
    public function remainingReturnableBase(PurchaseInvoiceLine $purchaseLine, ?int $excludeReturnId = null): string
    {
        $query = PurchaseReturnLine::query()
            ->where('purchase_invoice_line_id', $purchaseLine->id)
            ->whereHas('purchaseReturn', function ($q) use ($excludeReturnId): void {
                $q->where('status', PurchaseReturnStatus::Posted->value);
                if ($excludeReturnId !== null) {
                    $q->where('id', '!=', $excludeReturnId);
                }
            });

        $returned = (string) ($query->sum('base_quantity') ?: '0');
        $remaining = bcsub((string) $purchaseLine->base_quantity, bcadd($returned, '0', 6), 6);

        return bccomp($remaining, '0', 6) === -1 ? '0.000000' : $remaining;
    }

    public function assertReturnable(
        PurchaseInvoiceLine $purchaseLine,
        string $baseQuantity,
        ?int $excludeReturnId = null,
    ): void {
        $remaining = $this->remainingReturnableBase($purchaseLine, $excludeReturnId);
        if (bccomp($baseQuantity, $remaining, 6) === 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Return quantity exceeds remaining returnable quantity for this purchase line.',
            ]);
        }
    }
}
