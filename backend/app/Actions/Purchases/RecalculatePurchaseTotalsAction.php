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
            $discount = bcadd(
                $discount,
                bcadd(
                    (string) $line->discount_amount,
                    (string) ($line->disc_after_gst_amount ?? '0'),
                    4,
                ),
                4,
            );
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
        $withholdingBase = bcsub(bcadd($netLines, $charges, 4), $reductions, 4);

        if ((bool) ($invoice->withholding_is_pct ?? false)) {
            $invoice->advance_tax_amount = $this->pctAmount(
                $withholdingBase,
                (string) ($invoice->withholding_pct ?? '0'),
            );
        }

        $extras = bcadd((string) ($invoice->advance_tax_amount ?? '0'), (string) ($invoice->round_off ?? '0'), 4);
        $invoice->grand_total = bcadd($withholdingBase, $extras, 4);

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
     *     line_total: string,
     *     regular_disc_amount: string,
     *     special_disc_amount: string,
     *     disc_after_gst_amount: string
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
        string $discountApplyOn = 'trade',
        string $regularDiscAmount = '0',
        string $specialDiscAmount = '0',
        string $regularDiscMode = 'pct',
        string $specialDiscMode = 'pct',
        string $gstMode = 'pct',
        string $discAfterGstPct = '0',
        string $discAfterGstAmount = '0',
        string $discAfterGstMode = 'rs',
        string $furtherTaxAmount = '0',
        string $furtherTaxMode = 'pct',
    ): array {
        $this->assertPositiveQuantity($quantity, $conversionFactor, $unitCost);
        $this->assertPct($tradeDiscPct, 'trade_disc_pct');
        $this->assertPct($regularDiscPct, 'regular_disc_pct');
        $this->assertPct($specialDiscPct, 'special_disc_pct');
        $this->assertPct($taxPct, 'tax_pct');
        $this->assertPct($furtherTaxPct, 'further_tax_pct');
        $this->assertPct($discAfterGstPct, 'disc_after_gst_pct');

        $gross = bcmul($quantity, $unitCost, 4);
        $method = $this->normalizeCalculationMethod($calculationMethod);
        $mrpAmount = bcadd($mrp, '0', 4);
        if (bccomp($mrpAmount, '0', 4) === -1) {
            throw ValidationException::withMessages([
                'mrp' => 'MRP cannot be negative.',
            ]);
        }

        [$mrpEx, $mrpIncl] = $this->mrpPair($mrpAmount, $taxPct, $method);
        $discountBase = match ($discountApplyOn) {
            'mrp_ex_gst' => bcmul($quantity, $mrpEx, 4),
            'mrp_incl_gst' => bcmul($quantity, $mrpIncl, 4),
            default => $gross,
        };

        $tradeDiscount = $this->pctAmount($gross, $tradeDiscPct);
        $regularBase = $discountApplyOn === 'trade'
            ? bcsub($gross, $tradeDiscount, 4)
            : $discountBase;
        $regular = $this->resolveDualAmount(
            $regularDiscMode,
            $regularBase,
            $regularDiscPct,
            $regularDiscAmount,
            'regular_disc_amount',
        );
        $specialBase = bcsub($regularBase, $regular, 4);
        if (bccomp($specialBase, '0', 4) === -1) {
            throw ValidationException::withMessages([
                'regular_disc_amount' => 'Regular discount cannot exceed its calculation base.',
            ]);
        }
        $special = $this->resolveDualAmount(
            $specialDiscMode,
            $specialBase,
            $specialDiscPct,
            $specialDiscAmount,
            'special_disc_amount',
        );
        $discount = bcadd(bcadd($tradeDiscount, $regular, 4), $special, 4);

        // Backwards compatibility for API clients that only send aggregate fixed discount.
        $hasComponentDiscount =
            bccomp($tradeDiscPct, '0', 8) === 1
            || bccomp($regularDiscPct, '0', 8) === 1
            || bccomp($specialDiscPct, '0', 8) === 1
            || bccomp($regularDiscAmount, '0', 4) === 1
            || bccomp($specialDiscAmount, '0', 4) === 1;
        if (! $hasComponentDiscount && $explicitDiscount !== null) {
            $discount = bcadd($explicitDiscount, '0', 4);
        }

        if (bccomp($discount, '0', 4) === -1) {
            throw ValidationException::withMessages([
                'discount_amount' => 'Discount cannot be negative.',
            ]);
        }

        $afterDiscount = bcsub($gross, $discount, 4);
        if (bccomp($afterDiscount, '0', 4) === -1) {
            throw ValidationException::withMessages([
                'discount_amount' => 'Discount cannot exceed line gross amount.',
            ]);
        }

        $gstBase = match ($method) {
            'mrp_ex_gst' => bcmul($quantity, $mrpEx, 4),
            'mrp_incl_gst' => bcmul($quantity, $mrpIncl, 4),
            'trade_before_disc' => $gross,
            default => $afterDiscount,
        };

        $tax = $this->resolveTaxAmount(
            $method,
            $gstMode,
            $gstBase,
            $taxPct,
            $explicitTax,
        );

        $afterGst = bcadd($afterDiscount, $tax, 4);
        $discAfterGst = $this->resolveDualAmount(
            $discAfterGstMode,
            $afterGst,
            $discAfterGstPct,
            $discAfterGstAmount,
            'disc_after_gst_amount',
        );
        if (bccomp(bcsub($afterGst, $discAfterGst, 4), '0', 4) === -1) {
            throw ValidationException::withMessages([
                'disc_after_gst_amount' => 'Discount after GST cannot exceed the amount after GST.',
            ]);
        }

        // Further tax remains based on taxable value before GST, matching the
        // established server-side purchase tax rule.
        $furtherBase = $afterDiscount;
        $furtherTax = $this->resolveDualAmount(
            $furtherTaxMode,
            $furtherBase,
            $furtherTaxPct,
            $furtherTaxAmount,
            'further_tax_amount',
        );

        $lineTotal = bcadd(bcsub($afterGst, $discAfterGst, 4), $furtherTax, 4);
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
            'regular_disc_amount' => $regular,
            'special_disc_amount' => $special,
            'disc_after_gst_amount' => $discAfterGst,
        ];
    }

    private function normalizeCalculationMethod(string $method): string
    {
        return match ($method) {
            'gst_on_retail', 'gst_inclusive' => 'mrp_incl_gst',
            'gst_on_trade', 'disc_then_gst' => 'trade_after_disc',
            'no_gst' => 'mrp_ex_gst',
            default => $method,
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function mrpPair(string $mrp, string $taxPct, string $method): array
    {
        if ($method !== 'mrp_incl_gst' || bccomp($taxPct, '0', 8) !== 1) {
            return [$mrp, $this->addPct($mrp, $taxPct)];
        }

        $denominator = bcadd('100', $taxPct, 12);
        $exclusive = bcmul($mrp, bcdiv('100', $denominator, 12), 4);

        return [$exclusive, $mrp];
    }

    private function resolveTaxAmount(
        string $method,
        string $mode,
        string $base,
        string $taxPct,
        ?string $explicitTax,
    ): string {
        if (
            $mode === 'rs'
            || (
                bccomp($taxPct, '0', 8) !== 1
                && bccomp((string) ($explicitTax ?? '0'), '0', 4) === 1
            )
        ) {
            $tax = bcadd($explicitTax ?? '0', '0', 4);
            if (bccomp($tax, '0', 4) === -1) {
                throw ValidationException::withMessages([
                    'tax_amount' => 'Tax cannot be negative.',
                ]);
            }

            return $tax;
        }

        if ($method === 'mrp_incl_gst') {
            if (bccomp($taxPct, '0', 8) !== 1) {
                return '0.0000';
            }

            $denom = bcadd('100', $taxPct, 12);

            return bcmul($base, bcdiv($taxPct, $denom, 12), 4);
        }

        return $this->pctAmount($base, $taxPct);
    }

    private function resolveDualAmount(
        string $mode,
        string $base,
        string $pct,
        string $amount,
        string $field,
    ): string {
        if ($mode === 'pct') {
            return $this->pctAmount($base, $pct);
        }

        $resolved = bcadd($amount, '0', 4);
        if (bccomp($resolved, '0', 4) === -1) {
            throw ValidationException::withMessages([
                $field => 'Amount cannot be negative.',
            ]);
        }

        return $resolved;
    }

    private function pctAmount(string $amount, string $pct): string
    {
        if (bccomp($pct, '0', 8) !== 1) {
            return '0.0000';
        }

        return bcmul($amount, bcdiv($pct, '100', 12), 4);
    }

    private function addPct(string $amount, string $pct): string
    {
        return bcadd($amount, $this->pctAmount($amount, $pct), 4);
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
            'trade_after_disc',
            '0',
            'trade',
            '0',
            '0',
            'rs',
            'rs',
            'rs',
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
