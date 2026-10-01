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
        $furtherTax = '0.0000';

        foreach ($lines as $line) {
            $subtotal = bcadd($subtotal, bcmul((string) $line->quantity, (string) $line->unit_cost, 4), 4);
            $discount = bcadd($discount, (string) $line->discount_amount, 4);
            $tax = bcadd($tax, (string) $line->tax_amount, 4);
            $furtherTax = bcadd($furtherTax, (string) ($line->further_tax_amount ?? '0'), 4);
        }

        $invoice->subtotal = $subtotal;
        $invoice->discount_amount = $discount;
        $invoice->tax_amount = $tax;
        $invoice->further_tax_amount = $furtherTax;

        $netLines = bcadd(bcadd(bcsub($subtotal, $discount, 4), $tax, 4), $furtherTax, 4);
        $charges = bcadd(
            bcadd((string) $invoice->freight_amount, (string) ($invoice->loading_amount ?? '0'), 4),
            (string) $invoice->other_charges,
            4,
        );
        $reductions = bcadd((string) ($invoice->other_discount ?? '0'), (string) ($invoice->trade_offer ?? '0'), 4);
        $extras = bcadd((string) ($invoice->advance_tax_amount ?? '0'), (string) ($invoice->round_off ?? '0'), 4);

        $invoice->grand_total = bcadd(bcsub(bcadd($netLines, $charges, 4), $reductions, 4), $extras, 4);

        if (bccomp((string) $invoice->grand_total, '0', 4) === -1) {
            throw ValidationException::withMessages([
                'grand_total' => 'Purchase grand total cannot be negative.',
            ]);
        }

        $invoice->save();

        return $invoice;
    }

    /**
     * @return array{
     *     base_quantity: string,
     *     discount_amount: string,
     *     tax_amount: string,
     *     further_tax_amount: string,
     *     line_total: string
     * }
     */
    public function resolveLineMoney(
        string $quantity,
        string $conversionFactor,
        string $unitCost,
        string $tradeDiscPct = '0',
        string $regularDiscPct = '0',
        string $specialDiscPct = '0',
        string $taxPct = '0',
        string $furtherTaxPct = '0',
        ?string $explicitDiscount = null,
        ?string $explicitTax = null,
        string $calculationMethod = 'trade_after_disc',
        string $mrp = '0',
    ): array {
        $this->assertPositiveQuantity($quantity, $conversionFactor, $unitCost);
        $this->assertPct($tradeDiscPct, 'trade_disc_pct');
        $this->assertPct($regularDiscPct, 'regular_disc_pct');
        $this->assertPct($specialDiscPct, 'special_disc_pct');
        $this->assertPct($taxPct, 'tax_pct');
        $this->assertPct($furtherTaxPct, 'further_tax_pct');

        $gross = bcmul($quantity, $unitCost, 4);
        $usesPctDiscount = bccomp($tradeDiscPct, '0', 8) === 1
            || bccomp($regularDiscPct, '0', 8) === 1
            || bccomp($specialDiscPct, '0', 8) === 1;

        if ($usesPctDiscount) {
            $after = $this->applyPct($gross, $tradeDiscPct);
            $after = $this->applyPct($after, $regularDiscPct);
            $after = $this->applyPct($after, $specialDiscPct);
            $discount = bcsub($gross, $after, 4);
            $taxable = $after;
        } else {
            $discount = bcadd($explicitDiscount ?? '0', '0', 4);
            if (bccomp($discount, '0', 4) === -1) {
                throw ValidationException::withMessages([
                    'discount_amount' => 'Discount cannot be negative.',
                ]);
            }
            $taxable = bcsub($gross, $discount, 4);
        }

        if (bccomp($taxable, '0', 4) === -1) {
            throw ValidationException::withMessages([
                'discount_amount' => 'Discount cannot exceed line gross amount.',
            ]);
        }

        $method = $this->normalizeCalculationMethod($calculationMethod);
        $mrpAmount = bcadd($mrp, '0', 4);
        if (bccomp($mrpAmount, '0', 4) === -1) {
            throw ValidationException::withMessages([
                'mrp' => 'MRP cannot be negative.',
            ]);
        }

        $tax = $this->resolveTaxAmount(
            $method,
            $quantity,
            $gross,
            $taxable,
            $mrpAmount,
            $taxPct,
            $explicitTax,
        );

        $furtherTax = bccomp($furtherTaxPct, '0', 8) === 1
            ? bcmul($taxable, bcdiv($furtherTaxPct, '100', 12), 4)
            : '0.0000';

        $lineTotal = bcadd(bcadd($taxable, $tax, 4), $furtherTax, 4);
        if (bccomp($lineTotal, '0', 4) === -1) {
            throw ValidationException::withMessages([
                'line_total' => 'Line total cannot be negative.',
            ]);
        }

        return [
            'base_quantity' => bcmul($quantity, $conversionFactor, 6),
            'discount_amount' => $discount,
            'tax_amount' => $tax,
            'further_tax_amount' => $furtherTax,
            'line_total' => $lineTotal,
        ];
    }

    private function normalizeCalculationMethod(string $method): string
    {
        return match ($method) {
            'gst_on_retail', 'gst_inclusive' => 'mrp_incl_gst',
            'gst_on_trade' => 'trade_after_disc',
            'disc_then_gst' => 'trade_after_disc',
            'no_gst' => 'mrp_ex_gst',
            default => $method,
        };
    }

    private function resolveTaxAmount(
        string $method,
        string $quantity,
        string $gross,
        string $taxable,
        string $mrp,
        string $taxPct,
        ?string $explicitTax,
    ): string {
        if ($method === 'manual') {
            $tax = bcadd($explicitTax ?? '0', '0', 4);
            if (bccomp($tax, '0', 4) === -1) {
                throw ValidationException::withMessages([
                    'tax_amount' => 'Tax cannot be negative.',
                ]);
            }

            return $tax;
        }

        if (bccomp($taxPct, '0', 8) !== 1) {
            $tax = bcadd($explicitTax ?? '0', '0', 4);
            if (bccomp($tax, '0', 4) === -1) {
                throw ValidationException::withMessages([
                    'tax_amount' => 'Tax cannot be negative.',
                ]);
            }

            return $tax;
        }

        $mrpTotal = bcmul($quantity, $mrp, 4);

        $base = match ($method) {
            'mrp_ex_gst' => $mrpTotal,
            'mrp_incl_gst' => $mrpTotal,
            'trade_before_disc' => $gross,
            default => $taxable, // trade_after_disc
        };

        if ($method === 'mrp_incl_gst') {
            // MRP stored as inclusive: tax = incl * rate / (100 + rate)
            $denom = bcadd('100', $taxPct, 12);

            return bcmul($base, bcdiv($taxPct, $denom, 12), 4);
        }

        return bcmul($base, bcdiv($taxPct, '100', 12), 4);
    }

    /**
     * @return array{base_quantity: string, line_total: string}
     */
    public function lineAmounts(string $quantity, string $conversionFactor, string $unitCost, string $discount, string $tax): array
    {
        $resolved = $this->resolveLineMoney(
            $quantity,
            $conversionFactor,
            $unitCost,
            '0',
            '0',
            '0',
            '0',
            '0',
            $discount,
            $tax,
        );

        return [
            'base_quantity' => $resolved['base_quantity'],
            'line_total' => $resolved['line_total'],
        ];
    }

    public function inventoryUnitCost(string $unitCost, string $conversionFactor): string
    {
        return bcdiv($unitCost, $conversionFactor, 4);
    }

    private function applyPct(string $amount, string $pct): string
    {
        if (bccomp($pct, '0', 8) !== 1) {
            return $amount;
        }

        $factor = bcsub('1', bcdiv($pct, '100', 12), 12);

        return bcmul($amount, $factor, 4);
    }

    private function assertPositiveQuantity(string $quantity, string $conversionFactor, string $unitCost): void
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
    }

    private function assertPct(string $value, string $field): void
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,8})?$/', $value)) {
            throw ValidationException::withMessages([
                $field => 'Percentage must be a valid non-negative decimal.',
            ]);
        }
        if (bccomp($value, '100', 8) === 1) {
            throw ValidationException::withMessages([
                $field => 'Percentage cannot exceed 100.',
            ]);
        }
    }
}
