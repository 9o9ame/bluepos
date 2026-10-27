<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class PlatformMfaVerifyRequest extends FormRequest
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
            'challenge_ulid' => ['required', 'string', 'size:26'],
            'code' => ['required', 'string', 'max:12'],
            'trust_device' => ['sometimes', 'boolean'],
            'location_latitude' => [config('security.tenant_login_location_required') ? 'required' : 'sometimes', 'numeric', 'between:-90,90'],
            'location_longitude' => [config('security.tenant_login_location_required') ? 'required' : 'sometimes', 'numeric', 'between:-180,180'],
            'location_accuracy' => [config('security.tenant_login_location_required') ? 'required' : 'sometimes', 'numeric', 'min:0', 'max:20000000'],
        ];
    }
}
