<?php

namespace App\Http\Requests\Purchases;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'supplier_ulid' => ['required', 'string', 'size:26'],
            'order_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_ulid' => ['required', 'string', 'size:26'],
            'items.*.unit_ulid' => ['required', 'string', 'size:26'],
            'items.*.quantity' => ['required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/'],
            'items.*.unit_price' => ['required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
            'items.*.discount_percent' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,8})?$/'],
            'items.*.discount_amount' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
