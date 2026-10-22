<?php

namespace App\Http\Requests\Purchases;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\In;

class AmendPurchaseInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        $money = ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'];
        $pct = ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,8})?$/'];
        $qty = ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/'];

        return [
            'revision_reason' => ['required', 'string', 'max:500'],
            'supplier_invoice_number' => ['nullable', 'string', 'max:100'],
            'due_date' => ['nullable', 'date'],
            'invoice_type' => ['nullable', 'string', 'max:40'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'calculation_method' => ['nullable', 'string', 'max:40'],
            'discount_apply_on' => ['nullable', new In(['trade', 'mrp_ex_gst', 'mrp_incl_gst'])],
            'calculation_modes' => ['nullable', 'array'],
            'calculation_modes.regular_disc' => ['nullable', new In(['pct', 'rs'])],
            'calculation_modes.special_disc' => ['nullable', new In(['pct', 'rs'])],
            'calculation_modes.gst' => ['nullable', new In(['pct', 'rs'])],
            'calculation_modes.disc_after_gst' => ['nullable', new In(['pct', 'rs'])],
            'calculation_modes.further_tax' => ['nullable', new In(['pct', 'rs'])],
            'calculation_modes.advance_tax' => ['nullable', new In(['pct', 'rs'])],
            'auto_calc_mrp' => ['nullable', 'boolean'],
            'withholding_is_pct' => ['nullable', 'boolean'],
            'withholding_pct' => $pct,
            'default_sales_tax_pct' => $pct,
            'default_further_tax_pct' => $pct,
            'default_advance_tax_pct' => $pct,
            'default_price_type' => ['nullable', 'string', 'max:20'],
            'brand_label' => ['nullable', 'string', 'max:120'],
            'freight_amount' => $money,
            'loading_amount' => $money,
            'other_charges' => $money,
            'other_discount' => $money,
            'trade_offer' => $money,
            'advance_tax_amount' => $money,
            'round_off' => ['nullable', 'regex:/^-?(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'tax_type' => ['nullable', 'string', 'max:40'],
            'payment_terms' => ['nullable', 'string', 'max:40'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_ulid' => ['required', 'string', 'size:26'],
            'lines.*.unit_ulid' => ['required', 'string', 'size:26'],
            'lines.*.quantity' => ['required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/'],
            'lines.*.conversion_factor' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,8})?$/'],
            'lines.*.unit_cost' => ['required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
            'lines.*.discount_amount' => $money,
            'lines.*.tax_amount' => $money,
            'lines.*.further_tax_amount' => $money,
            'lines.*.supplier_product_code' => ['nullable', 'string', 'max:100'],
            'lines.*.batch_number' => ['nullable', 'string', 'max:100'],
            'lines.*.expiry_date' => ['nullable', 'date'],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
            'lines.*.brand_label' => ['nullable', 'string', 'max:120'],
            'lines.*.hs_code' => ['nullable', 'string', 'max:40'],
            'lines.*.pack_size' => ['nullable', 'string', 'max:40'],
            'lines.*.qty_ctn' => $qty,
            'lines.*.free_pcs' => $qty,
            'lines.*.price_type' => ['nullable', 'string', 'max:20'],
            'lines.*.mrp' => $money,
            'lines.*.trade_disc_pct' => $pct,
            'lines.*.regular_disc_pct' => $pct,
            'lines.*.regular_disc_amount' => $money,
            'lines.*.special_disc_pct' => $pct,
            'lines.*.special_disc_amount' => $money,
            'lines.*.disc_after_gst_pct' => $pct,
            'lines.*.disc_after_gst_amount' => $money,
            'lines.*.tax_pct' => $pct,
            'lines.*.further_tax_pct' => $pct,
        ];
    }
}
