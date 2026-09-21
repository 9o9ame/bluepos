<?php

namespace App\Http\Requests\Platform;

use App\Enums\PlatformUserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StorePlatformUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['nullable', 'string', Password::min(8)],
            'must_change_password' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::enum(PlatformUserStatus::class)],
            'role_ulids' => ['sometimes', 'array'],
            'role_ulids.*' => ['string', 'size:26'],
            'reason' => ['nullable', 'string', 'min:3', 'max:500'],
        ];
    }
}
