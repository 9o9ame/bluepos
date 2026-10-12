<?php

namespace App\Http\Requests\CustomQuery;

use Illuminate\Foundation\Http\FormRequest;

class RunCustomQueryRequest extends FormRequest
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
            'query' => ['required', 'string', 'max:5000'],
        ];
    }
}
