<?php

namespace App\Http\Requests\Platform;

use App\Enums\SubscriptionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignTenantSubscriptionRequest extends FormRequest
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
            'plan_ulid' => ['required', 'string', 'size:26'],
            'status' => ['required', Rule::enum(SubscriptionStatus::class)],
            'trial_starts_at' => ['nullable', 'date'],
            'trial_ends_at' => ['nullable', 'date'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'grace_ends_at' => ['nullable', 'date'],
        ];
    }
}
