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
        ];
    }
}
