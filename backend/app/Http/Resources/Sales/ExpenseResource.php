<?php

namespace App\Http\Resources\Sales;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Expense
 */
class ExpenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'expense_date' => $this->expense_date?->toDateString(),
            'amount' => $this->amount,
            'reference' => $this->reference,
            'description' => $this->description,
            'journal_entry_ulid' => $this->journal_entry_ulid,
            'expense_account' => $this->whenLoaded('expenseAccount', fn () => [
                'ulid' => $this->expenseAccount?->ulid,
                'code' => $this->expenseAccount?->code,
                'name' => $this->expenseAccount?->name,
            ]),
            'payment_account' => $this->whenLoaded('paymentAccount', fn () => [
                'ulid' => $this->paymentAccount?->ulid,
                'code' => $this->paymentAccount?->code,
                'name' => $this->paymentAccount?->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
