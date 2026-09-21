<?php

namespace App\Http\Requests\Auth;

use App\Support\IdentityNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('tenant_code')) {
            $this->merge(['tenant_code' => IdentityNormalizer::tenantCode((string) $this->input('tenant_code'))]);
        }
        if ($this->has('username')) {
            $this->merge(['username' => IdentityNormalizer::username((string) $this->input('username'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tenant_code' => ['required', 'string', 'max:32'],
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string'],
        ];
    }
}
