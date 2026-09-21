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
        ];
    }
}
