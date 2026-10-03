<?php

namespace App\Actions\SaleSchemes;

use App\Catalog\TenantCatalog;
use App\Enums\SaleSchemeApplyMode;
use App\Enums\SaleSchemeType;
use App\Models\SaleScheme;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpsertSaleSchemeAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantCatalog $catalog,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): SaleScheme
    {
        return DB::transaction(function () use ($data): SaleScheme {
            $product = $this->catalog->product((string) $data['reward_product_ulid']);
            $this->assertRewardProductActive($product->is_active);

            return SaleScheme::query()->create([
                'tenant_id' => $this->tenantContext->tenantId(),
                'name' => $data['name'],
                'scheme_type' => $data['scheme_type'] ?? SaleSchemeType::SpendAmount->value,
                'apply_mode' => $data['apply_mode'] ?? SaleSchemeApplyMode::Salesman->value,
                'min_sale_amount' => $data['min_sale_amount'],
                'reward_product_id' => $product->id,
                'max_reward_qty' => $data['max_reward_qty'],
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
                'is_stackable' => (bool) ($data['is_stackable'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'created_by' => $this->tenantContext->userId(),
            ])->fresh(['rewardProduct']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(SaleScheme $scheme, array $data): SaleScheme
    {
        return DB::transaction(function () use ($scheme, $data): SaleScheme {
            $tenantId = $this->tenantContext->tenantId();
            $scheme = SaleScheme::query()
                ->forTenant($tenantId)
                ->whereKey($scheme->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (array_key_exists('reward_product_ulid', $data)) {
                $product = $this->catalog->product((string) $data['reward_product_ulid']);
                $this->assertRewardProductActive($product->is_active);
                $scheme->reward_product_id = $product->id;
            }

            foreach (['name', 'scheme_type', 'apply_mode', 'min_sale_amount', 'max_reward_qty', 'starts_on', 'ends_on', 'is_stackable', 'is_active'] as $field) {
                if (array_key_exists($field, $data)) {
                    $scheme->{$field} = $data[$field];
                }
            }

            $scheme->updated_by = $this->tenantContext->userId();
            $scheme->save();

            return $scheme->fresh(['rewardProduct']);
        });
    }

    private function assertRewardProductActive(bool $isActive): void
    {
        if (! $isActive) {
            throw ValidationException::withMessages([
                'reward_product_ulid' => 'The reward product must be active.',
            ]);
        }
    }
}
