<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class PlatformLoginRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
            'location_latitude' => [config('security.tenant_login_location_required') ? 'required' : 'sometimes', 'numeric', 'between:-90,90'],
            'location_longitude' => [config('security.tenant_login_location_required') ? 'required' : 'sometimes', 'numeric', 'between:-180,180'],
            'location_accuracy' => [config('security.tenant_login_location_required') ? 'required' : 'sometimes', 'numeric', 'min:0', 'max:100000'],
        ];
    }
}
