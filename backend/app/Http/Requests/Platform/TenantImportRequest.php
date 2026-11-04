<?php

namespace App\Http\Requests\Platform;

use App\Models\Tenant;
use App\Platform\PlatformContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class TenantImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = Tenant::query()->where('ulid', $this->route('tenantUlid'))->firstOrFail();

        return Gate::forUser(app(PlatformContext::class)->user())->allows('importData', $tenant);
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file', 'extensions:xlsx,csv,pdf', 'mimes:xlsx,csv,txt,pdf', 'max:10240'],
            'warehouse_ulid' => ['nullable', 'ulid']];
    }
}
