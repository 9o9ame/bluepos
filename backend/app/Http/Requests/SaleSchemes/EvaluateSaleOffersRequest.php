<?php

namespace App\Http\Requests\SaleSchemes;

use Illuminate\Foundation\Http\FormRequest;

class EvaluateSaleOffersRequest extends FormRequest
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
            'subtotal' => ['required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/', 'numeric', 'min:0'],
            'document_date' => ['nullable', 'date'],
        ];
    }
}
