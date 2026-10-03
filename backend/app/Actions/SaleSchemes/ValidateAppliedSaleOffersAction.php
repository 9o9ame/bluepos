<?php

namespace App\Actions\SaleSchemes;

use App\Models\Product;
use App\Models\SaleScheme;
use App\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

class ValidateAppliedSaleOffersAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Validate free packaging lines and explicitly selected schemes.
     *
     * Eligible schemes are never automatically applied.
     * The salesman must explicitly select a scheme and quantity.
     *
     * @param list<array{product_ulid: string, qty: string, line_kind?: string}> $freeLines
     * @param list<array{scheme_ulid: string, qty: string}> $appliedSchemes
     *
     * @return array{
     *     packaging_lines: list<array{
     *         product: Product,
     *         qty: string,
     *         line_kind: string
     *     }>,
     *     scheme_lines: list<array{
     *         scheme: SaleScheme,
     *         product: Product,
     *         qty: string,
     *         line_kind: string,
     *         auto_applied: bool
     *     }>
     * }
     */
    public function execute(
        string $subtotal,
        array $freeLines,
        array $appliedSchemes,
        ?string $documentDate = null,
    ): array {
        $tenantId = $this->tenantContext->tenantId();

        /*
         * Evaluate eligibility using the server-side subtotal and
         * document date.
         */
        $evaluated = app(EvaluateSaleOffersAction::class)->execute(
            $subtotal,
            $documentDate,
        );

        $eligibleByUlid = collect($evaluated['schemes'])
            ->keyBy('ulid');

        /*
         * Validate free packaging.
         */
        $packagingLines = [];

        foreach ($freeLines as $index => $line) {
            $kind = (string) (
                $line['line_kind'] ?? 'free_packaging'
            );

            if ($kind !== 'free_packaging') {
                throw ValidationException::withMessages([
                    "free_lines.$index.line_kind" =>
                        'Only free packaging lines are allowed here.',
                ]);
            }

            $productUlid = (string) (
                $line['product_ulid'] ?? ''
            );

            $product = Product::query()
                ->forTenant($tenantId)
                ->where('ulid', $productUlid)
                ->first();

            if (
                ! $product ||
                ! $product->is_active ||
                ! $product->is_packaging
            ) {
                throw ValidationException::withMessages([
                    "free_lines.$index.product_ulid" =>
                        'This packaging item is not available.',
                ]);
            }

            $qty = (string) (
                $line['qty'] ?? ''
            );

            if (! $this->isValidQuantity($qty)) {
                throw ValidationException::withMessages([
                    "free_lines.$index.qty" =>
                        'Quantity must be a valid positive decimal with up to 6 places.',
                ]);
            }

            $qty = $this->normalizeQuantity($qty);

            if (
                $product->max_free_qty_per_sale !== null &&
                bccomp(
                    $qty,
                    (string) $product->max_free_qty_per_sale,
                    6,
                ) > 0
            ) {
                throw ValidationException::withMessages([
                    "free_lines.$index.qty" =>
                        'Quantity exceeds the configured packaging limit.',
                ]);
            }

            $packagingLines[] = [
                'product' => $product,
                'qty' => $qty,
                'line_kind' => 'free_packaging',
            ];
        }

        /*
         * Validate explicitly selected schemes.
         */
        $schemeLines = [];
        $selectedSchemeUlids = [];

        foreach ($appliedSchemes as $index => $selectedScheme) {
            $schemeUlid = (string) (
                $selectedScheme['scheme_ulid'] ?? ''
            );

            $qty = (string) (
                $selectedScheme['qty'] ?? ''
            );

            if ($schemeUlid === '') {
                throw ValidationException::withMessages([
                    "applied_schemes.$index.scheme_ulid" =>
                        'A scheme must be selected.',
                ]);
            }

            if (
                in_array(
                    $schemeUlid,
                    $selectedSchemeUlids,
                    true,
                )
            ) {
                throw ValidationException::withMessages([
                    "applied_schemes.$index.scheme_ulid" =>
                        'The same scheme cannot be selected more than once.',
                ]);
            }

            $selectedSchemeUlids[] = $schemeUlid;

            /*
             * The scheme must currently be eligible for this sale.
             */
            $eligible = $eligibleByUlid->get($schemeUlid);

            if (! $eligible) {
                throw ValidationException::withMessages([
                    "applied_schemes.$index.scheme_ulid" =>
                        "Scheme {$schemeUlid} is not eligible for this sale.",
                ]);
            }

            if (! $this->isValidQuantity($qty)) {
                throw ValidationException::withMessages([
                    "applied_schemes.$index.qty" =>
                        'Quantity must be a valid positive decimal with up to 6 places.',
                ]);
            }

            $qty = $this->normalizeQuantity($qty);

            $maxRewardQty = $this->normalizeQuantity(
                (string) $eligible['max_reward_qty']
            );

            if (
                bccomp(
                    $qty,
                    $maxRewardQty,
                    6,
                ) > 0
            ) {
                throw ValidationException::withMessages([
                    "applied_schemes.$index.qty" =>
                        'Quantity exceeds the maximum reward quantity for this scheme.',
                ]);
            }

            /*
             * Reload the actual tenant-scoped scheme and reward product.
             * The evaluated offer determines eligibility; this query gives
             * us the model that will be persisted on the SaleItem.
             *
             * @var SaleScheme|null $scheme
             */
            $scheme = SaleScheme::query()
                ->forTenant($tenantId)
                ->where('ulid', $schemeUlid)
                ->with('rewardProduct')
                ->first();

            if (! $scheme) {
                throw ValidationException::withMessages([
                    "applied_schemes.$index.scheme_ulid" =>
                        'The selected scheme could not be found.',
                ]);
            }

            if (
                ! $scheme->rewardProduct ||
                ! $scheme->rewardProduct->is_active
            ) {
                throw ValidationException::withMessages([
                    "applied_schemes.$index.scheme_ulid" =>
                        'The reward product for this scheme is not available.',
                ]);
            }

            /*
             * Enforce stackability:
             *
             * - A non-stackable scheme cannot be combined with another scheme.
             * - A stackable scheme cannot be combined with an already-selected
             *   non-stackable scheme.
             */
            if (! $scheme->is_stackable) {
                if ($schemeLines !== []) {
                    throw ValidationException::withMessages([
                        'applied_schemes' =>
                            'These schemes cannot be combined.',
                    ]);
                }
            } elseif ($this->hasNonStackableScheme($schemeLines)) {
                throw ValidationException::withMessages([
                    'applied_schemes' =>
                        'These schemes cannot be combined.',
                ]);
            }

            $schemeLines[] = [
                'scheme' => $scheme,
                'product' => $scheme->rewardProduct,
                'qty' => $qty,
                'line_kind' => 'free_scheme',
                'auto_applied' => false,
            ];
        }

        /*
         * Important:
         *
         * Eligible schemes are NOT automatically applied.
         * The salesman must explicitly select a scheme and quantity.
         */
        return [
            'packaging_lines' => $packagingLines,
            'scheme_lines' => $schemeLines,
        ];
    }

    /**
     * Validate a positive decimal quantity with up to 6 decimal places.
     */
    private function isValidQuantity(string $value): bool
    {
        if (
            ! preg_match(
                '/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/',
                $value
            )
        ) {
            return false;
        }

        return bccomp($value, '0', 6) > 0;
    }

    /**
     * Normalize quantity to 6 decimal places.
     */
    private function normalizeQuantity(string $value): string
    {
        return bcadd($value, '0', 6);
    }

    /**
     * Determine whether an already-added scheme is non-stackable.
     *
     * @param list<array<string, mixed>> $schemeLines
     */
    private function hasNonStackableScheme(array $schemeLines): bool
    {
        foreach ($schemeLines as $line) {
            /** @var SaleScheme $scheme */
            $scheme = $line['scheme'];

            if (! $scheme->is_stackable) {
                return true;
            }
        }

        return false;
    }
}