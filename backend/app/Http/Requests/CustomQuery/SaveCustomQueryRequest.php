<?php

namespace App\Http\Requests\CustomQuery;

use Illuminate\Foundation\Http\FormRequest;

class SaveCustomQueryRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:160'],
            'query' => ['required', 'string', 'max:5000'],
        ];
    }
}
