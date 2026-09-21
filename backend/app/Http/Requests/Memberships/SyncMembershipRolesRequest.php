<?php

namespace App\Http\Requests\Memberships;

use Illuminate\Foundation\Http\FormRequest;

class SyncMembershipRolesRequest extends FormRequest
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
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'size:26'],
        ];
    }
}
