<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class AmendSaleReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'revision_reason' => ['required', 'string', 'max:500'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sale_item_ulid' => ['nullable', 'string', 'size:26'],
            'lines.*.product_ulid' => ['nullable', 'string', 'size:26'],
            'lines.*.quantity' => ['required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/'],
            'lines.*.unit_price' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
            'lines.*.discount_percent' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,8})?$/'],
            'lines.*.reason' => ['nullable', 'string', 'max:255'],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
