<?php

namespace App\Http\Resources;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tenant
 */
class TenantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'code' => $this->code,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'timezone' => $this->timezone,
            'currency_code' => $this->currency_code,
        ];
    }
}
