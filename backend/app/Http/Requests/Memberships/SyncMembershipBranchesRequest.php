<?php

namespace App\Http\Requests\Memberships;

use Illuminate\Foundation\Http\FormRequest;

class SyncMembershipBranchesRequest extends FormRequest
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
            'branches' => ['required', 'array'],
            'branches.*' => ['required', 'string', 'size:26'],
        ];
    }
}
