<?php

namespace App\Http\Requests\Products;

use Illuminate\Foundation\Http\FormRequest;

class SyncProductBarcodesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Empty list is allowed — products may be defined by name only.
            'barcodes' => ['present', 'array'],
            'barcodes.*.barcode' => ['required', 'string', 'max:64', 'distinct'],
            'barcodes.*.unit_ulid' => ['required', 'string', 'size:26'],
            'barcodes.*.conversion_factor' => ['required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,8})?$/', 'numeric', 'gt:0'],
            'barcodes.*.is_primary' => ['sometimes', 'boolean'],
            'barcodes.*.is_active' => ['sometimes', 'boolean'],
        ];
    }
}
