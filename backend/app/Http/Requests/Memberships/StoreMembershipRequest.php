<?php

namespace App\Http\Requests\Memberships;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMembershipRequest extends FormRequest
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
            'username' => ['required', 'string', 'max:63'],
            'recovery_email' => [
                'nullable',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
                Rule::unique('users', 'recovery_email'),
            ],
            'recovery_phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'string', 'min:8'],
            'must_change_password' => ['sometimes', 'boolean'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'size:26'],
            'branches' => ['sometimes', 'array'],
            'branches.*' => ['required', 'string', 'size:26'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recovery_email.unique' => 'This recovery email is already used by another account. Use a different email.',
        ];
    }
}
