<?php

namespace App\Actions\SaleSchemes;

use App\Enums\SaleSchemeApplyMode;
use App\Models\Product;
use App\Models\SaleScheme;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

class EvaluateSaleOffersAction
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @return array{
     *   packaging: list<array<string, mixed>>,
     *   schemes: list<array<string, mixed>>
     * }
     */
    public function execute(string $subtotal, ?string $documentDate = null): array
    {
        $tenantId = $this->tenantContext->tenantId();
        $onDate = $documentDate
            ? CarbonImmutable::parse($documentDate)->toDateString()
            : CarbonImmutable::now()->toDateString();

        $packaging = Product::query()
            ->forTenant($tenantId)
            ->where('is_active', true)
            ->where('is_packaging', true)
            ->orderBy('name')
            ->get(['ulid', 'name', 'product_number', 'max_free_qty_per_sale'])
            ->map(static fn (Product $product): array => [
                'ulid' => $product->ulid,
                'name' => $product->name,
                'product_number' => $product->product_number,
                'max_free_qty_per_sale' => $product->max_free_qty_per_sale,
                'line_kind' => 'free_packaging',
            ])
            ->values()
            ->all();

        $schemes = SaleScheme::query()
            ->forTenant($tenantId)
            ->where('is_active', true)
            ->with('rewardProduct:id,ulid,name,product_number,is_active')
            ->orderBy('min_sale_amount')
            ->orderBy('name')
            ->get()
            ->filter(function (SaleScheme $scheme) use ($onDate, $subtotal): bool {
                if ($scheme->starts_on && $scheme->starts_on->toDateString() > $onDate) {
                    return false;
                }
                if ($scheme->ends_on && $scheme->ends_on->toDateString() < $onDate) {
                    return false;
                }
                if (! $scheme->rewardProduct || ! $scheme->rewardProduct->is_active) {
                    return false;
                }

                return bccomp((string) $subtotal, (string) $scheme->min_sale_amount, 4) >= 0;
            })
            ->map(static function (SaleScheme $scheme): array {
                $applyMode = $scheme->apply_mode instanceof SaleSchemeApplyMode
                    ? $scheme->apply_mode->value
                    : (string) $scheme->apply_mode;

                return [
                    'ulid' => $scheme->ulid,
                    'name' => $scheme->name,
                    'scheme_type' => $scheme->scheme_type->value,
                    'apply_mode' => $applyMode,
                    'auto_apply' => $applyMode === SaleSchemeApplyMode::Auto->value,
                    'requires_salesman_decision' => $applyMode === SaleSchemeApplyMode::Salesman->value,
                    'min_sale_amount' => $scheme->min_sale_amount,
                    'max_reward_qty' => $scheme->max_reward_qty,
                    'is_stackable' => $scheme->is_stackable,
                    'reward_product' => [
                        'ulid' => $scheme->rewardProduct->ulid,
                        'name' => $scheme->rewardProduct->name,
                        'product_number' => $scheme->rewardProduct->product_number,
                    ],
                    'line_kind' => 'free_scheme',
                ];
            })
            ->values()
            ->all();

        return [
            'packaging' => $packaging,
            'schemes' => $schemes,
        ];
    }
}
