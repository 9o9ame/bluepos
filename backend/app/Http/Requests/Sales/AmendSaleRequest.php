<?php

namespace App\Http\Requests\Sales;

class AmendSaleRequest extends StoreSaleRequest
{
    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        unset(
            $rules['sale_date'],
            $rules['customer_ulid'],
            $rules['customer_name'],
            $rules['customer_phone'],
            $rules['salesman_ulid'],
            $rules['payment_due'],
            $rules['initial_payment'],
            $rules['initial_payment.amount'],
            $rules['initial_payment.method'],
            $rules['initial_payment.reference'],
            $rules['initial_payment.account_ulid'],
            $rules['initial_payments'],
            $rules['initial_payments.*.amount'],
            $rules['initial_payments.*.method'],
            $rules['initial_payments.*.reference'],
            $rules['initial_payments.*.account_ulid'],
            $rules['warehouse_ulid'],
        );

        $rules['revision_reason'] = ['required', 'string', 'max:500'];

        return $rules;
    }
}
