<?php

namespace App\Http\Requests\Roles;

use App\Enums\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:64', 'alpha_dash'],
            'description' => ['nullable', 'string', 'max:255'],
            'branch_access' => ['nullable', Rule::enum(BranchAccess::class)],
        ];
    }
}
