<?php

namespace App\Http\Requests\Sales;

class StoreSaleQuotationRequest extends StoreSaleRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        unset(
            $rules['sale_date'],
            $rules['payment_due'],
            $rules['initial_payment'],
            $rules['initial_payment.amount'],
            $rules['initial_payment.method'],
            $rules['initial_payment.reference'],
        );

        $rules['quotation_date'] = ['nullable', 'date'];

        return $rules;
    }
}
