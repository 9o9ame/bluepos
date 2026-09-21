<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class SyncPlatformUserRolesRequest extends FormRequest
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
            'role_ulids' => ['required', 'array'],
            'role_ulids.*' => ['string', 'size:26'],
            'reason' => ['nullable', 'string', 'min:3', 'max:500'],
        ];
    }
}
