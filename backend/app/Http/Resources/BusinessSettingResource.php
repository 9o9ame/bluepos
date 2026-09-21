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
        ];
    }
}
