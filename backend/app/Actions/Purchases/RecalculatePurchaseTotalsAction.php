<?php

namespace App\Actions\Purchases;

use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use Illuminate\Validation\ValidationException;

class RecalculatePurchaseTotalsAction
{
    public function execute(PurchaseInvoice $invoice): PurchaseInvoice
    {
        $lines = PurchaseInvoiceLine::query()
            ->where('purchase_invoice_id', $invoice->id)
            ->get();

        $subtotal = '0.0000';
        $discount = '0.0000';
        $tax = '0.0000';

        foreach ($lines as $line) {
            $subtotal = bcadd($subtotal, bcmul((string) $line->quantity, (string) $line->unit_cost, 4), 4);
            $discount = bcadd($discount, (string) $line->discount_amount, 4);
            $tax = bcadd($tax, (string) $line->tax_amount, 4);
        }

        $invoice->subtotal = $subtotal;
        $invoice->discount_amount = $discount;
        $invoice->tax_amount = $tax;
        $invoice->grand_total = bcadd(
            bcadd(bcsub($subtotal, $discount, 4), $tax, 4),
            bcadd((string) $invoice->freight_amount, (string) $invoice->other_charges, 4),
            4,
        );

        if (bccomp((string) $invoice->grand_total, '0', 4) === -1) {
            throw ValidationException::withMessages([
                'grand_total' => 'Purchase grand total cannot be negative.',
            ]);
        }

        $invoice->save();

        return $invoice;
    }

    /**
     * @return array{base_quantity: string, line_total: string}
     */
    public function lineAmounts(string $quantity, string $conversionFactor, string $unitCost, string $discount, string $tax): array
    {
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
        ];
    }

    public function inventoryUnitCost(string $unitCost, string $conversionFactor): string
    {
        return bcdiv($unitCost, $conversionFactor, 4);
    }
}
