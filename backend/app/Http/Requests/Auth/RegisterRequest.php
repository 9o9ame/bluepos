<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => Str::lower(trim((string) $this->input('email'))),
            ]);
        }

        if ($this->has('currency_code')) {
            $this->merge([
                'currency_code' => Str::upper((string) $this->input('currency_code')),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'tenant_name' => ['required', 'string', 'max:255'],
            'timezone' => ['sometimes', 'timezone'],
            'currency_code' => ['sometimes', 'string', 'size:3', 'alpha'],
        ];
    }
}
