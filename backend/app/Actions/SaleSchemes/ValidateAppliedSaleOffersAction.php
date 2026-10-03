<?php

namespace App\Actions\SaleSchemes;

use App\Enums\SaleSchemeApplyMode;
use App\Models\Product;
use App\Models\SaleScheme;
use App\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

class ValidateAppliedSaleOffersAction
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Validate packaging free lines and selected schemes for a sale payload.
     *
     * @param  list<array{product_ulid: string, qty: string, line_kind?: string}>  $freeLines
     * @param  list<string>  $appliedSchemeUlids
     * @return array{
     *   packaging_lines: list<array{product: Product, qty: string, line_kind: string}>,
     *   scheme_lines: list<array{scheme: SaleScheme, product: Product, qty: string, line_kind: string, auto_applied: bool}>
     * }
     */
    public function execute(
        string $subtotal,
        array $freeLines,
        array $appliedSchemeUlids,
        ?string $documentDate = null,
    ): array {
        $tenantId = $this->tenantContext->tenantId();

        $evaluated = app(EvaluateSaleOffersAction::class)->execute($subtotal, $documentDate);
        $eligibleByUlid = collect($evaluated['schemes'])->keyBy('ulid');

        $packagingLines = [];
        foreach ($freeLines as $index => $line) {
            $kind = (string) ($line['line_kind'] ?? 'free_packaging');
            if ($kind !== 'free_packaging') {
                continue;
            }

            $product = Product::query()
                ->forTenant($tenantId)
                ->where('ulid', $line['product_ulid'])
                ->first();

            if (! $product || ! $product->is_active || ! $product->is_packaging) {
                throw ValidationException::withMessages([
                    "free_lines.$index.product_ulid" => 'This packaging item is not available.',
                ]);
            }

            $qty = (string) $line['qty'];
            if (bccomp($qty, '0', 6) <= 0) {
                throw ValidationException::withMessages([
                    "free_lines.$index.qty" => 'Quantity must be greater than zero.',
                ]);
            }

            if ($product->max_free_qty_per_sale !== null
                && bccomp($qty, (string) $product->max_free_qty_per_sale, 6) > 0) {
                throw ValidationException::withMessages([
                    "free_lines.$index.qty" => 'Quantity exceeds the configured packaging limit.',
                ]);
            }

            $packagingLines[] = [
                'product' => $product,
                'qty' => $qty,
                'line_kind' => 'free_packaging',
            ];
        }

        $applied = array_values(array_unique($appliedSchemeUlids));
        $schemeLines = [];
        $nonStackableUsed = false;

        foreach ($applied as $schemeUlid) {
            $eligible = $eligibleByUlid->get($schemeUlid);
            if (! $eligible) {
                throw ValidationException::withMessages([
                    'applied_scheme_ulids' => "Scheme {$schemeUlid} is not eligible for this sale.",
                ]);
            }

            if ($eligible['apply_mode'] === SaleSchemeApplyMode::Salesman->value) {
                // salesman mode: must be explicitly selected (already in $applied)
            }

            /** @var SaleScheme $scheme */
            $scheme = SaleScheme::query()
                ->forTenant($tenantId)
                ->where('ulid', $schemeUlid)
                ->with('rewardProduct')
                ->firstOrFail();

            if (! $scheme->is_stackable) {
                if ($nonStackableUsed || count($applied) > 1) {
                    throw ValidationException::withMessages([
                        'applied_scheme_ulids' => 'These schemes cannot be combined.',
                    ]);
                }
                $nonStackableUsed = true;
            }

            $schemeLines[] = [
                'scheme' => $scheme,
                'product' => $scheme->rewardProduct,
                'qty' => (string) $scheme->max_reward_qty,
                'line_kind' => 'free_scheme',
                'auto_applied' => false,
            ];
        }

        // Auto schemes must be present when eligible (server will include them on post).
        foreach ($evaluated['schemes'] as $eligible) {
            if ($eligible['apply_mode'] !== SaleSchemeApplyMode::Auto->value) {
                continue;
            }
            if (in_array($eligible['ulid'], $applied, true)) {
                continue;
            }

            /** @var SaleScheme $scheme */
            $scheme = SaleScheme::query()
                ->forTenant($tenantId)
                ->where('ulid', $eligible['ulid'])
                ->with('rewardProduct')
                ->firstOrFail();

            if (! $scheme->is_stackable && ($nonStackableUsed || count($schemeLines) > 0)) {
                continue;
            }

            $schemeLines[] = [
                'scheme' => $scheme,
                'product' => $scheme->rewardProduct,
                'qty' => (string) $scheme->max_reward_qty,
                'line_kind' => 'free_scheme',
                'auto_applied' => true,
            ];

            if (! $scheme->is_stackable) {
                $nonStackableUsed = true;
            }
        }

        return [
            'packaging_lines' => $packagingLines,
            'scheme_lines' => $schemeLines,
        ];
    }
}
