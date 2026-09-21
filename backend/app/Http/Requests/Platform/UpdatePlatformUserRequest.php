<?php

namespace App\Http\Requests\Platform;

use App\Enums\PlatformUserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlatformUserRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', 'max:255'],
            'status' => ['sometimes', Rule::enum(PlatformUserStatus::class)],
            'current_password' => ['nullable', 'string'],
        ];
    }
}
