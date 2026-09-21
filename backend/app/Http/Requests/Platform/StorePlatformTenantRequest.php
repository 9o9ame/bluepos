<?php

namespace App\Http\Requests\Platform;

use App\Enums\TenantStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlatformTenantRequest extends FormRequest
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
            'tenant_name' => ['required', 'string', 'max:160'],
            'tenant_code' => ['required', 'string', 'max:32'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'recovery_email' => ['required', 'email', 'max:255'],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_username' => ['required', 'string', 'max:63'],
            'timezone' => ['required', 'string', 'max:64'],
            'currency_code' => ['required', 'string', 'size:3'],
            'plan_ulid' => ['required', 'string', 'size:26'],
            'status' => ['required', Rule::enum(TenantStatus::class)],
            'trial_starts_at' => ['nullable', 'date'],
            'trial_ends_at' => ['nullable', 'date', 'after_or_equal:trial_starts_at'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'password' => ['nullable', 'string', 'min:8'],
        ];
    }
}
