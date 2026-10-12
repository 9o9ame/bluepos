<?php

namespace App\Http\Requests\Settings;

use App\Enums\PriceType;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBusinessSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach ([
            'opening_balance_equity_account_ulid',
            'default_cash_account_ulid',
            'sales_clearing_account_ulid',
            'purchase_clearing_account_ulid',
            'sales_revenue_account_ulid',
            'sales_tax_payable_account_ulid',
            'inventory_account_ulid',
            'cogs_account_ulid',
        ] as $field) {
            if (! $this->exists($field)) {
                continue;
            }

            $raw = $this->input($field);
            $this->merge([
                $field => $raw === null || $raw === ''
                    ? null
                    : trim((string) $raw),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'business_name' => ['sometimes', 'required', 'string', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'backup_email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'country_code' => ['sometimes', 'required', 'string', 'size:2'],
            'currency_code' => ['sometimes', 'required', 'string', 'size:3'],
            'timezone' => ['sometimes', 'required', 'string', 'max:64'],
            'date_format' => ['sometimes', 'required', 'string', 'max:32'],
            'number_format' => ['sometimes', 'required', 'string', 'max:32'],
            'tax_registration_number' => ['nullable', 'string', 'max:64'],
            'invoice_prefix' => ['nullable', 'string', 'max:16'],
            'receipt_footer' => ['nullable', 'string', 'max:255'],
            'default_tax_percent' => ['sometimes', 'required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,8})?$/', 'numeric', 'min:0', 'max:100'],
            'negative_stock_allowed' => ['sometimes', 'boolean'],
            'expiry_tracking_enabled' => ['sometimes', 'boolean'],
            'batch_tracking_enabled' => ['sometimes', 'boolean'],
            'default_price_level' => ['sometimes', 'required', Rule::enum(PriceType::class)],
            'software_options' => ['sometimes', 'array:sale,purchase,general'],

            'software_options.sale' => ['sometimes', 'array:expiry_warning_days,show_receiving_window,manual_date_change,keep_pending_invoice_date,print_dotted_line,dont_round_off,message,show_remarks,show_discount_percent,show_discount_amount,show_previous_rates,amount_editable,amount_change_updates_qty,disable_txt_id,fbr_message,show_qty_option_discount,invoice_on,default_yes,disallow_duplicate_products'],
            'software_options.sale.expiry_warning_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'software_options.sale.show_receiving_window' => ['sometimes', 'boolean'],
            'software_options.sale.manual_date_change' => ['sometimes', 'boolean'],
            'software_options.sale.keep_pending_invoice_date' => ['sometimes', 'boolean'],
            'software_options.sale.print_dotted_line' => ['sometimes', 'boolean'],
            'software_options.sale.dont_round_off' => ['sometimes', 'boolean'],
            'software_options.sale.message' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'software_options.sale.show_remarks' => ['sometimes', 'boolean'],
            'software_options.sale.show_discount_percent' => ['sometimes', 'boolean'],
            'software_options.sale.show_discount_amount' => ['sometimes', 'boolean'],
            'software_options.sale.show_previous_rates' => ['sometimes', 'boolean'],
            'software_options.sale.amount_editable' => ['sometimes', 'boolean'],
            'software_options.sale.amount_change_updates_qty' => ['sometimes', 'boolean'],
            'software_options.sale.disable_txt_id' => ['sometimes', 'boolean'],
            'software_options.sale.fbr_message' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'software_options.sale.show_qty_option_discount' => ['sometimes', 'boolean'],
            'software_options.sale.invoice_on' => ['sometimes', 'boolean'],
            'software_options.sale.default_yes' => ['sometimes', 'boolean'],
            'software_options.sale.disallow_duplicate_products' => ['sometimes', 'boolean'],

            'software_options.purchase' => ['sometimes', 'array:show_expiry_batch,show_bonus,show_sales_tax,show_rate_change_window,show_vehicle_bilty,amount_editable,show_stock_taking,stock_taking_method,default_vendor_ulid,default_customer_ulid,po_heading'],
            'software_options.purchase.show_expiry_batch' => ['sometimes', 'boolean'],
            'software_options.purchase.show_bonus' => ['sometimes', 'boolean'],
            'software_options.purchase.show_sales_tax' => ['sometimes', 'boolean'],
            'software_options.purchase.show_rate_change_window' => ['sometimes', 'boolean'],
            'software_options.purchase.show_vehicle_bilty' => ['sometimes', 'boolean'],
            'software_options.purchase.amount_editable' => ['sometimes', 'boolean'],
            'software_options.purchase.show_stock_taking' => ['sometimes', 'boolean'],
            'software_options.purchase.stock_taking_method' => ['sometimes', Rule::in(['full', 'simple', 'individual'])],
            'software_options.purchase.default_vendor_ulid' => [
                'sometimes',
                'nullable',
                'string',
                'size:26',
                Rule::exists('suppliers', 'ulid')->where('tenant_id', $tenantId)->where('is_active', true),
            ],
            'software_options.purchase.default_customer_ulid' => [
                'sometimes',
                'nullable',
                'string',
                'size:26',
                Rule::exists('customers', 'ulid')->where('tenant_id', $tenantId)->where('is_active', true),
            ],
            'software_options.purchase.po_heading' => ['sometimes', 'nullable', 'string', 'max:160'],

            'software_options.general' => ['sometimes', 'array:view_invoices_days,view_history_days,show_purchase_qty,weight_in_invoices,single_user_product_ids,use_custom_font_all,font_family,font_size,apply_custom_font_style,custom_font_family,custom_font_size,use_last_purchase_rate_profit,keep_product_prices,auto_generate_barcodes,recalc_sheet,show_previous_balance,show_salesman_vcs,heading_ulid,direct_print,book_set,sale_modal,purchase_modal,freight,default_format,description1,description2,disable_search_dates,bill_to_bill,products_group_option'],
            'software_options.general.view_invoices_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'software_options.general.view_history_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'software_options.general.show_purchase_qty' => ['sometimes', 'boolean'],
            'software_options.general.weight_in_invoices' => ['sometimes', 'boolean'],
            'software_options.general.single_user_product_ids' => ['sometimes', 'boolean'],
            'software_options.general.use_custom_font_all' => ['sometimes', 'boolean'],
            'software_options.general.font_family' => ['sometimes', 'nullable', 'string', 'max:80'],
            'software_options.general.font_size' => ['sometimes', 'integer', 'min:8', 'max:48'],
            'software_options.general.apply_custom_font_style' => ['sometimes', 'boolean'],
            'software_options.general.custom_font_family' => ['sometimes', 'nullable', 'string', 'max:80'],
            'software_options.general.custom_font_size' => ['sometimes', 'nullable', 'integer', 'min:8', 'max:48'],
            'software_options.general.use_last_purchase_rate_profit' => ['sometimes', 'boolean'],
            'software_options.general.keep_product_prices' => ['sometimes', 'boolean'],
            'software_options.general.auto_generate_barcodes' => ['sometimes', 'boolean'],
            'software_options.general.recalc_sheet' => ['sometimes', 'boolean'],
            'software_options.general.show_previous_balance' => ['sometimes', 'boolean'],
            'software_options.general.show_salesman_vcs' => ['sometimes', 'boolean'],
            'software_options.general.heading_ulid' => [
                'sometimes',
                'nullable',
                'string',
                'size:26',
                Rule::exists('report_headings', 'ulid')->where('tenant_id', $tenantId),
            ],
            'software_options.general.direct_print' => ['sometimes', 'boolean'],
            'software_options.general.book_set' => ['sometimes', 'boolean'],
            'software_options.general.sale_modal' => ['sometimes', 'boolean'],
            'software_options.general.purchase_modal' => ['sometimes', 'boolean'],
            'software_options.general.freight' => ['sometimes', 'regex:/^(?:0|[1-9]\d{0,15})(?:\.\d{1,4})?$/', 'numeric', 'min:0'],
            'software_options.general.default_format' => ['sometimes', 'nullable', 'string', 'max:80'],
            'software_options.general.description1' => ['sometimes', 'nullable', 'string', 'max:120'],
            'software_options.general.description2' => ['sometimes', 'nullable', 'string', 'max:120'],
            'software_options.general.disable_search_dates' => ['sometimes', 'boolean'],
            'software_options.general.bill_to_bill' => ['sometimes', 'boolean'],
            'software_options.general.products_group_option' => ['sometimes', 'boolean'],
            'opening_balance_equity_account_ulid' => [
                'sometimes',
                'nullable',
                'string',
                'size:26',
                Rule::exists('accounts', 'ulid')
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true),
            ],
            'default_cash_account_ulid' => [
                'sometimes',
                'nullable',
                'string',
                'size:26',
                Rule::exists('accounts', 'ulid')
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true),
            ],
            'sales_clearing_account_ulid' => [
                'sometimes',
                'nullable',
                'string',
                'size:26',
                'different:default_cash_account_ulid',
                Rule::exists('accounts', 'ulid')
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true),
            ],
            'purchase_clearing_account_ulid' => [
                'sometimes',
                'nullable',
                'string',
                'size:26',
                Rule::exists('accounts', 'ulid')
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true),
            ],

            'sales_revenue_account_ulid' => [
                'sometimes',
                'nullable',
                'string',
                'size:26',
                Rule::exists('accounts', 'ulid')
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true),
            ],
            'sales_tax_payable_account_ulid' => [
                'sometimes',
                'nullable',
                'string',
                'size:26',
                Rule::exists('accounts', 'ulid')
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true),
            ],
            'inventory_account_ulid' => [
                'sometimes',
                'nullable',
                'string',
                'size:26',
                Rule::exists('accounts', 'ulid')
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true),
            ],
            'cogs_account_ulid' => [
                'sometimes',
                'nullable',
                'string',
                'size:26',
                Rule::exists('accounts', 'ulid')
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true),
            ],
        ];
    }
}
