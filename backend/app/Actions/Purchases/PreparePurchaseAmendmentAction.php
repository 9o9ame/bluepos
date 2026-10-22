<?php

namespace App\Actions\Purchases;

use App\Catalog\TenantCatalog;
use App\Enums\ProductStatus;
use App\Models\ProductBarcode;
use App\Models\PurchaseInvoice;
use App\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

class PreparePurchaseAmendmentAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantCatalog $catalog,
        private readonly RecalculatePurchaseTotalsAction $recalculate,
    ) {}

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $before
     * @return array{header:array<string,mixed>,lines:list<array<string,mixed>>}
     */
    public function execute(PurchaseInvoice $invoice, array $data, array $before): array
    {
        $modes = is_array($data['calculation_modes'] ?? null)
            ? $data['calculation_modes']
            : (is_array($before['calculation_modes'] ?? null) ? $before['calculation_modes'] : []);

        $calculationMethod = (string) ($data['calculation_method'] ?? $before['calculation_method'] ?? 'trade_after_disc');
        $discountApplyOn = (string) ($data['discount_apply_on'] ?? $before['discount_apply_on'] ?? 'trade');

        $lines = [];
        $subtotal = '0.0000';
        $discount = '0.0000';
        $tax = '0.0000';
        $furtherTax = '0.0000';

        foreach (($data['lines'] ?? []) as $index => $item) {
            $product = $this->catalog->product((string) $item['product_ulid']);
            if ($product->status !== ProductStatus::Active || ! $product->is_active) {
                throw ValidationException::withMessages([
                    "lines.$index.product_ulid" => 'Purchases cannot use an inactive product.',
                ]);
            }

            $unit = $this->catalog->unit((string) $item['unit_ulid']);
            $conversion = $this->resolveConversionFactor(
                $product->id,
                $product->base_unit_id,
                $product->secondary_unit_id,
                (string) $product->secondary_conversion_factor,
                $unit->id,
            );

            $quantity = $this->qty((string) $item['quantity'], "lines.$index.quantity", false);
            $unitCost = $this->money((string) $item['unit_cost'], "lines.$index.unit_cost");
            $freePcs = $this->qty((string) ($item['free_pcs'] ?? '0'), "lines.$index.free_pcs", true);
            $qtyCtn = $this->qty((string) ($item['qty_ctn'] ?? '0'), "lines.$index.qty_ctn", true);
            $mrp = $this->money((string) ($item['mrp'] ?? '0'), "lines.$index.mrp");

            $batch = isset($item['batch_number']) ? trim((string) $item['batch_number']) : null;
            $expiry = isset($item['expiry_date']) && $item['expiry_date'] !== ''
                ? (string) $item['expiry_date']
                : null;

            if ($product->track_batch && ! $batch) {
                throw ValidationException::withMessages([
                    "lines.$index.batch_number" => 'Batch number is required for this product.',
                ]);
            }

            if ($product->track_expiry && ! $expiry) {
                throw ValidationException::withMessages([
                    "lines.$index.expiry_date" => 'Expiry date is required for this product.',
                ]);
            }

            $amounts = $this->recalculate->resolveLineMoney(
                $quantity,
                $conversion,
                $unitCost,
                (string) ($item['trade_disc_pct'] ?? '0'),
                (string) ($item['regular_disc_pct'] ?? '0'),
                (string) ($item['special_disc_pct'] ?? '0'),
                (string) ($item['tax_pct'] ?? '0'),
                (string) ($item['further_tax_pct'] ?? '0'),
                isset($item['discount_amount']) ? (string) $item['discount_amount'] : '0',
                isset($item['tax_amount']) ? (string) $item['tax_amount'] : '0',
                $calculationMethod,
                $mrp,
                $discountApplyOn,
                (string) ($item['regular_disc_amount'] ?? '0'),
                (string) ($item['special_disc_amount'] ?? '0'),
                (string) ($modes['regular_disc'] ?? 'pct'),
                (string) ($modes['special_disc'] ?? 'pct'),
                (string) ($modes['gst'] ?? 'pct'),
                (string) ($item['disc_after_gst_pct'] ?? '0'),
                (string) ($item['disc_after_gst_amount'] ?? '0'),
                (string) ($modes['disc_after_gst'] ?? 'rs'),
                (string) ($item['further_tax_amount'] ?? '0'),
                (string) ($modes['further_tax'] ?? 'pct'),
            );

            $lines[] = [
                'product' => $product,
                'unit' => $unit,
                'quantity' => $quantity,
                'conversion_factor' => $conversion,
                'base_quantity' => $amounts['base_quantity'],
                'unit_cost' => $unitCost,
                'discount_amount' => $amounts['discount_amount'],
                'tax_amount' => $amounts['tax_amount'],
                'further_tax_amount' => $amounts['further_tax_amount'],
                'line_total' => $amounts['line_total'],
                'supplier_product_code' => $item['supplier_product_code'] ?? null,
                'batch_number' => $batch,
                'expiry_date' => $expiry,
                'notes' => $item['notes'] ?? null,
                'brand_label' => $item['brand_label'] ?? null,
                'hs_code' => $item['hs_code'] ?? null,
                'pack_size' => $item['pack_size'] ?? null,
                'qty_ctn' => $qtyCtn,
                'free_pcs' => $freePcs,
                'price_type' => $item['price_type'] ?? 'trade',
                'mrp' => $mrp,
                'trade_disc_pct' => bcadd((string) ($item['trade_disc_pct'] ?? '0'), '0', 8),
                'regular_disc_pct' => bcadd((string) ($item['regular_disc_pct'] ?? '0'), '0', 8),
                'regular_disc_amount' => $amounts['regular_disc_amount'],
                'special_disc_pct' => bcadd((string) ($item['special_disc_pct'] ?? '0'), '0', 8),
                'special_disc_amount' => $amounts['special_disc_amount'],
                'disc_after_gst_pct' => bcadd((string) ($item['disc_after_gst_pct'] ?? '0'), '0', 8),
                'disc_after_gst_amount' => $amounts['disc_after_gst_amount'],
                'tax_pct' => bcadd((string) ($item['tax_pct'] ?? '0'), '0', 8),
                'further_tax_pct' => bcadd((string) ($item['further_tax_pct'] ?? '0'), '0', 8),
            ];

            $subtotal = bcadd($subtotal, bcmul($quantity, $unitCost, 4), 4);
            $discount = bcadd(
                $discount,
                bcadd($amounts['discount_amount'], $amounts['disc_after_gst_amount'], 4),
                4,
            );
            $tax = bcadd($tax, $amounts['tax_amount'], 4);
            $furtherTax = bcadd($furtherTax, $amounts['further_tax_amount'], 4);
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'A posted Purchase Invoice amendment must contain at least one line.',
            ]);
        }

        $freight = $this->money((string) ($data['freight_amount'] ?? $before['freight_amount'] ?? '0'), 'freight_amount');
        $loading = $this->money((string) ($data['loading_amount'] ?? $before['loading_amount'] ?? '0'), 'loading_amount');
        $otherCharges = $this->money((string) ($data['other_charges'] ?? $before['other_charges'] ?? '0'), 'other_charges');
        $otherDiscount = $this->money((string) ($data['other_discount'] ?? $before['other_discount'] ?? '0'), 'other_discount');
        $tradeOffer = $this->money((string) ($data['trade_offer'] ?? $before['trade_offer'] ?? '0'), 'trade_offer');
        $roundOff = $this->signedMoney((string) ($data['round_off'] ?? $before['round_off'] ?? '0'));

        $netLines = bcadd(bcadd(bcsub($subtotal, $discount, 4), $tax, 4), $furtherTax, 4);
        $charges = bcadd(bcadd($freight, $loading, 4), $otherCharges, 4);
        $reductions = bcadd($otherDiscount, $tradeOffer, 4);
        $withholdingBase = bcsub(bcadd($netLines, $charges, 4), $reductions, 4);

        $withholdingIsPct = (bool) ($data['withholding_is_pct'] ?? $before['withholding_is_pct'] ?? false);
        $withholdingPct = $this->pct((string) ($data['withholding_pct'] ?? $before['withholding_pct'] ?? '0'));
        $advanceTax = $withholdingIsPct
            ? $this->pctAmount($withholdingBase, $withholdingPct)
            : $this->money((string) ($data['advance_tax_amount'] ?? $before['advance_tax_amount'] ?? '0'), 'advance_tax_amount');

        $grandTotal = bcadd($withholdingBase, bcadd($advanceTax, $roundOff, 4), 4);
        if (bccomp($grandTotal, '0', 4) < 0) {
            throw ValidationException::withMessages([
                'grand_total' => 'Purchase grand total cannot be negative.',
            ]);
        }

        return [
            'header' => [
                'supplier_invoice_number' => $data['supplier_invoice_number'] ?? $before['supplier_invoice_number'] ?? null,
                'po_number' => $before['po_number'] ?? null,
                'invoice_type' => $data['invoice_type'] ?? $before['invoice_type'] ?? 'tax_gst',
                'currency_code' => $data['currency_code'] ?? $before['currency_code'] ?? 'PKR',
                'calculation_method' => $calculationMethod,
                'discount_apply_on' => $discountApplyOn,
                'calculation_modes' => $modes,
                'auto_calc_mrp' => (bool) ($data['auto_calc_mrp'] ?? $before['auto_calc_mrp'] ?? true),
                'withholding_is_pct' => $withholdingIsPct,
                'withholding_pct' => $withholdingPct,
                'default_sales_tax_pct' => $this->pct((string) ($data['default_sales_tax_pct'] ?? $before['default_sales_tax_pct'] ?? '0')),
                'default_further_tax_pct' => $this->pct((string) ($data['default_further_tax_pct'] ?? $before['default_further_tax_pct'] ?? '0')),
                'default_advance_tax_pct' => $this->pct((string) ($data['default_advance_tax_pct'] ?? $before['default_advance_tax_pct'] ?? '0')),
                'default_price_type' => $data['default_price_type'] ?? $before['default_price_type'] ?? 'trade',
                'brand_label' => $data['brand_label'] ?? $before['brand_label'] ?? null,
                'due_date' => $data['due_date'] ?? $before['due_date'] ?? null,
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'tax_amount' => $tax,
                'further_tax_amount' => $furtherTax,
                'freight_amount' => $freight,
                'loading_amount' => $loading,
                'other_charges' => $otherCharges,
                'other_discount' => $otherDiscount,
                'trade_offer' => $tradeOffer,
                'advance_tax_amount' => $advanceTax,
                'round_off' => $roundOff,
                'grand_total' => $grandTotal,
                'notes' => $data['notes'] ?? $before['notes'] ?? null,
                'tax_type' => $data['tax_type'] ?? $before['tax_type'] ?? 'standard',
                'payment_terms' => $data['payment_terms'] ?? $before['payment_terms'] ?? 'credit',
            ],
            'lines' => $lines,
        ];
    }

    private function resolveConversionFactor(
        int $productId,
        ?int $baseUnitId,
        ?int $secondaryUnitId,
        string $secondaryFactor,
        int $unitId,
    ): string {
        if ($baseUnitId === $unitId) {
            return '1.00000000';
        }

        if ($secondaryUnitId === $unitId) {
            return bcadd($secondaryFactor ?: '1', '0', 8);
        }

        $factor = ProductBarcode::query()
            ->forTenant($this->tenantContext->tenantId())
            ->where('product_id', $productId)
            ->where('unit_id', $unitId)
            ->where('is_active', true)
            ->orderByDesc('is_primary')
            ->value('conversion_factor');

        if ($factor === null) {
            throw ValidationException::withMessages([
                'unit_ulid' => 'The selected unit is not configured for this product.',
            ]);
        }

        return bcadd((string) $factor, '0', 8);
    }

    private function money(string $value, string $field): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages([$field => 'Amount must be a valid non-negative decimal.']);
        }

        return bcadd($value, '0', 4);
    }

    private function signedMoney(string $value): string
    {
        if (! preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages(['round_off' => 'Round off must be a valid decimal.']);
        }

        return bcadd($value, '0', 4);
    }

    private function qty(string $value, string $field, bool $allowZero): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/', $value)) {
            throw ValidationException::withMessages([$field => 'Quantity must be a valid non-negative decimal.']);
        }

        if (! $allowZero && bccomp($value, '0', 6) !== 1) {
            throw ValidationException::withMessages([$field => 'Quantity must be greater than zero.']);
        }

        return bcadd($value, '0', 6);
    }

    private function pct(string $value): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,8})?$/', $value) || bccomp($value, '100', 8) === 1) {
            throw ValidationException::withMessages(['percent' => 'Percentage must be between 0 and 100.']);
        }

        return bcadd($value, '0', 8);
    }

    private function pctAmount(string $amount, string $pct): string
    {
        if (bccomp($pct, '0', 8) !== 1) {
            return '0.0000';
        }

        return bcmul($amount, bcdiv($pct, '100', 12), 4);
    }
}
