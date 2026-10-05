<?php

namespace App\Http\Resources;

use App\Models\BusinessSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BusinessSetting
 */
class BusinessSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $equity = $this->relationLoaded('openingBalanceEquityAccount')
            ? $this->openingBalanceEquityAccount
            : $this->openingBalanceEquityAccount()->first();

        $cash = $this->relationLoaded('defaultCashAccount')
            ? $this->defaultCashAccount
            : $this->defaultCashAccount()->first();

        $clearing = $this->relationLoaded('salesClearingAccount')
            ? $this->salesClearingAccount
            : $this->salesClearingAccount()->first();

        return [
            'ulid' => $this->ulid,
            'business_name' => $this->business_name,
            'legal_name' => $this->legal_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'city' => $this->city,
            'country_code' => $this->country_code,
            'currency_code' => $this->currency_code,
            'timezone' => $this->timezone,
            'date_format' => $this->date_format,
            'number_format' => $this->number_format,
            'tax_registration_number' => $this->tax_registration_number,
            'invoice_prefix' => $this->invoice_prefix,
            'receipt_footer' => $this->receipt_footer,
            'default_tax_percent' => $this->default_tax_percent,
            'negative_stock_allowed' => $this->negative_stock_allowed,
            'expiry_tracking_enabled' => $this->expiry_tracking_enabled,
            'batch_tracking_enabled' => $this->batch_tracking_enabled,
            'default_price_level' => $this->default_price_level,
            'opening_balance_equity_account_ulid' => $equity?->ulid,
            'opening_balance_equity_account' => $equity ? [
                'ulid' => $equity->ulid,
                'code' => $equity->code,
                'name' => $equity->name,
            ] : null,
            'default_cash_account_ulid' => $cash?->ulid,
            'default_cash_account' => $cash ? [
                'ulid' => $cash->ulid,
                'code' => $cash->code,
                'name' => $cash->name,
            ] : null,
            'sales_clearing_account_ulid' => $clearing?->ulid,
            'sales_clearing_account' => $clearing ? [
                'ulid' => $clearing->ulid,
                'code' => $clearing->code,
                'name' => $clearing->name,
            ] : null,
        ];
    }
}
