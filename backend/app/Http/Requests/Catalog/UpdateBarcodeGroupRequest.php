<?php

namespace App\Http\Requests\Catalog;

use App\Models\BarcodeGroup;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBarcodeGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        if ($this->exists('code')) {
            $payload['code'] = strtoupper(trim((string) $this->input('code')));
        }
        if ($this->exists('name')) {
            $payload['name'] = trim((string) $this->input('name'));
        }

        $this->merge($payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $ulid = (string) $this->route('barcodeGroupUlid');
        $barcodeGroupId = BarcodeGroup::query()
            ->forTenant($tenantId)
            ->where('ulid', $ulid)
            ->value('id');

        return [
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:64',
                'alpha_dash',
                Rule::unique('barcode_groups', 'code')
                    ->where('tenant_id', $tenantId)
                    ->ignore($barcodeGroupId),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
