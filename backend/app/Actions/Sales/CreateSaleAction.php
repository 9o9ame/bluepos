<?php

namespace App\Actions\Sales;

use App\Actions\Inventory\PostStockMovementAction;
use App\Actions\SaleSchemes\ValidateAppliedSaleOffersAction;
use App\Catalog\TenantCatalog;
use App\Enums\PriceType;
use App\Enums\ProductStatus;
use App\Enums\SaleLineKind;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Enums\WarehouseStatus;
use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Models\PartyProfile;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductPrice;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a posted sale.
 *
 * Every money figure is recalculated server-side with BCMath; the payload's
 * client totals are never trusted.
 *
 * Free packaging and free scheme lines are priced at 0 but still consume
 * stock, so stock goes out for every line.
 *
 * Requires an Idempotency-Key: replaying the same key returns the original
 * sale instead of creating a second one.
 */
class CreateSaleAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantCatalog $catalog,
        private readonly ValidateAppliedSaleOffersAction $validateOffers,
        private readonly PostStockMovementAction $postMovement,
        private readonly CollectSalePaymentAction $collectPayment,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function execute(array $data, string $idempotencyKey): Sale
    {
        $tenantId = $this->tenantContext->tenantId();

        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
            throw new ApiException(
                'IDEMPOTENCY_KEY_REQUIRED',
                'A valid Idempotency-Key header is required to post a sale.',
                422,
            );
        }

        return DB::transaction(function () use (
            $data,
            $idempotencyKey,
            $tenantId
        ): Sale {
            $existing = Sale::query()
                ->forTenant($tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing->fresh(static::with()) ?? $existing;
            }

            $warehouse = $this->resolveWarehouse(
                $data['warehouse_ulid'] ?? null
            );

            $customer = $this->resolveCustomer(
                $data['customer_ulid'] ?? null
            );

            $paymentDue = (bool) ($data['payment_due'] ?? false);
            if ($paymentDue && $customer === null) {
                throw ValidationException::withMessages([
                    'customer_ulid' =>
                        'Select a customer before marking a sale as Payment Due.',
                ]);
            }

            $priceType = $this->resolvePriceType(
                $data['price_type'] ?? null
            );

            $salesman = $this->resolveSalesman(
                $data['salesman_ulid'] ?? null
            );

            $lines = $this->normalizeLines(
                $data['items'] ?? [],
                $priceType,
            );

            if ($lines === []) {
                throw ValidationException::withMessages([
                    'items' => 'Add at least one sale line.',
                ]);
            }

            $paidLines = array_values(
                array_filter(
                    $lines,
                    static fn (array $line): bool =>
                        $line['line_kind'] === SaleLineKind::Sale->value,
                )
            );

            if ($paidLines === []) {
                throw ValidationException::withMessages([
                    'items' => 'A sale needs at least one paid line.',
                ]);
            }

            /*
             * The scheme threshold is calculated from paid sale lines only.
             * Free packaging and free scheme rewards never increase the
             * qualifying sale amount.
             */
            $paidSubtotal = '0.0000';

            foreach ($paidLines as $line) {
                // Scheme qualification uses the paid amount after line discount
                // but before tax. Tax should not make a sale qualify for a reward.
                $paidSubtotal = bcadd(
                    $paidSubtotal,
                    bcsub($line['gross_amount'], $line['discount_amount'], 4),
                    4
                );
            }

            /*
             * The server validates every explicitly selected free offer.
             *
             * No scheme is automatically added here. The salesman must
             * explicitly send an applied_schemes entry.
             */
            $offers = $this->validateOffers->execute(
                $paidSubtotal,
                $data['free_lines'] ?? [],
                $data['applied_schemes'] ?? [],
                $data['sale_date'] ?? null,
            );

            /*
             * Only validated server-generated free lines are persisted.
             * Client-supplied free lines are never trusted directly.
             */
            $lines = array_merge(
                $paidLines,
                $this->offersToLines($offers)
            );

            $this->persist(
                $lines,
                $warehouse,
                $customer,
                $salesman,
                $data,
                $idempotencyKey,
                $tenantId,
                $priceType,
            );

            $sale = Sale::query()
                ->forTenant($tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();

            $initialPayment = $data['initial_payment'] ?? null;
            if (is_array($initialPayment)) {
                $this->collectPayment->execute(
                    $sale,
                    $initialPayment,
                    $this->initialPaymentIdempotencyKey($idempotencyKey),
                );
            }

            $this->audit->record('SALE_POSTED', [
                'resource_type' => 'sale',
                'resource_ulid' => $sale->ulid,
                'line_count' => count($lines),
            ]);

            return $sale->fresh(static::with()) ?? $sale;
        });
    }

    /**
     * Resolve and price a Sales document without persisting or posting stock.
     *
     * Quotations reuse this exact preparation path so product pricing,
     * discounts, tax, units, packaging and schemes stay server-authoritative.
     *
     * @param array<string, mixed> $data
     * @return array{
     *     warehouse: Warehouse,
     *     customer: ?Customer,
     *     salesman: ?PartyProfile,
     *     price_type: PriceType,
     *     lines: list<array<string, mixed>>
     * }
     */
    public function prepareDocument(array $data): array
    {
        $warehouse = $this->resolveWarehouse(
            $data['warehouse_ulid'] ?? null
        );

        $customer = $this->resolveCustomer(
            $data['customer_ulid'] ?? null
        );

        $priceType = $this->resolvePriceType(
            $data['price_type'] ?? null
        );

        $salesman = $this->resolveSalesman(
            $data['salesman_ulid'] ?? null
        );

        $lines = $this->normalizeLines(
            $data['items'] ?? [],
            $priceType,
        );

        if ($lines === []) {
            throw ValidationException::withMessages([
                'items' => 'Add at least one sale line.',
            ]);
        }

        $paidLines = array_values(
            array_filter(
                $lines,
                static fn (array $line): bool =>
                    $line['line_kind'] === SaleLineKind::Sale->value,
            )
        );

        if ($paidLines === []) {
            throw ValidationException::withMessages([
                'items' => 'A sale document needs at least one paid line.',
            ]);
        }

        $paidSubtotal = '0.0000';

        foreach ($paidLines as $line) {
            $paidSubtotal = bcadd(
                $paidSubtotal,
                bcsub($line['gross_amount'], $line['discount_amount'], 4),
                4
            );
        }

        $offers = $this->validateOffers->execute(
            $paidSubtotal,
            $data['free_lines'] ?? [],
            $data['applied_schemes'] ?? [],
            $data['sale_date'] ?? null,
        );

        return [
            'warehouse' => $warehouse,
            'customer' => $customer,
            'salesman' => $salesman,
            'price_type' => $priceType,
            'lines' => array_merge(
                $paidLines,
                $this->offersToLines($offers)
            ),
        ];
    }

    /**
     * @return list<string>
     */
    public static function with(): array
    {
        return [
            'customer',
            'salesmanParty',
            'branch',
            'warehouse',
            'items.product',
            'items.unit',
            'items.saleScheme',
            'payments',
        ];
    }

    private function initialPaymentIdempotencyKey(string $saleIdempotencyKey): string
    {
        return 'sale-initial-payment:'.hash('sha256', $saleIdempotencyKey);
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $data
     */
    private function persist(
        array $lines,
        Warehouse $warehouse,
        ?Customer $customer,
        ?PartyProfile $salesman,
        array $data,
        string $idempotencyKey,
        int $tenantId,
        PriceType $priceType,
    ): void {
        $subtotal = '0.0000';
        $discountTotal = '0.0000';
        $taxTotal = '0.0000';

        $sale = Sale::query()->create([
            'tenant_id' => $tenantId,
            'branch_id' => $warehouse->branch_id,
            'warehouse_id' => $warehouse->id,
            'customer_id' => $customer?->id,
            'salesman_party_profile_id' => $salesman?->id,
            'document_number' => $this->nextDocumentNumber($tenantId),
            'status' => SaleStatus::Posted,
            'sale_date' => $data['sale_date'] ?? now()->toDateString(),
            'price_type' => $priceType->value,
            'subtotal' => '0.0000',
            'discount_amount' => '0.0000',
            'tax_amount' => '0.0000',
            'grand_total' => '0.0000',
            'notes' => $data['notes'] ?? null,
            'idempotency_key' => $idempotencyKey,
            'created_by' => $this->tenantContext->userId(),
            'posted_at' => now(),
        ]);

        foreach ($lines as $line) {
            /** @var Product $product */
            $product = $line['product'];

            $quantity = $line['quantity'];

            $item = SaleItem::query()->create([
                'tenant_id' => $tenantId,
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'unit_id' => $line['unit']->id,
                'barcode' => $line['barcode'] ?? null,
                'conversion_factor' => $line['conversion_factor'],
                'line_kind' => $line['line_kind'],
                'sale_scheme_id' => $line['sale_scheme_id'] ?? null,
                'quantity' => $quantity,
                'stock_quantity' => $line['stock_quantity'],
                'price_type' => $line['price_type'],
                'unit_price' => $line['unit_price'],
                'gross_amount' => $line['gross_amount'],
                'discount_percent' => $line['discount_percent'],
                'discount_amount' => $line['discount_amount'],
                'tax_percent' => $line['tax_percent'],
                'tax_amount' => $line['tax_amount'],
                'line_total' => $line['line_total'],
                'notes' => $line['notes'] ?? null,
            ]);

            $subtotal = bcadd(
                $subtotal,
                $line['gross_amount'],
                4
            );

            $discountTotal = bcadd(
                $discountTotal,
                $line['discount_amount'],
                4
            );

            $taxTotal = bcadd(
                $taxTotal,
                $line['tax_amount'],
                4
            );

            /*
             * Every sale line consumes stock, including:
             *
             * - normal sale items
             * - free packaging
             * - free scheme rewards
             */
            $this->postMovement->execute([
                'warehouse' => $warehouse,
                'product' => $product,
                'movement_type' => StockMovementType::Sale,
                'quantity' => bcsub('0', $line['stock_quantity'], 6),
                'reference_type' => 'sale',
                'reference_ulid' => $sale->ulid,
                'reference_line_ulid' => $item->ulid,
                'occurred_at' => $sale->sale_date?->copy()->startOfDay() ?? now(),
                'idempotency_key' => 'sale:' . $sale->ulid . ':' . $item->ulid,
            ]);
        }

        $sale->subtotal = $subtotal;
        $sale->discount_amount = $discountTotal;
        $sale->tax_amount = $taxTotal;

        $sale->grand_total = bcadd(
            bcsub($subtotal, $discountTotal, 4),
            $taxTotal,
            4
        );

        $sale->save();
    }

    /**
     * Turn validated free packaging + scheme lines into SaleItem rows.
     *
     * Both are priced at zero because free rewards must not increase
     * the customer's payable amount.
     *
     * @param array{
     *     packaging_lines: list<array<string, mixed>>,
     *     scheme_lines: list<array<string, mixed>>
     * } $offers
     *
     * @return list<array<string, mixed>>
     */
    private function offersToLines(array $offers): array
    {
        $lines = [];

        foreach ($offers['packaging_lines'] as $packaging) {
            /** @var Product $product */
            $product = $packaging['product'];
            $unit = $this->baseUnitForProduct($product);
            $quantity = $this->normalizeQuantity((string) $packaging['qty']);

            $lines[] = [
                'product' => $product,
                'unit' => $unit,
                'barcode' => null,
                'conversion_factor' => '1.00000000',
                'quantity' => $quantity,
                'stock_quantity' => $quantity,
                'price_type' => null,
                'line_kind' => SaleLineKind::FreePackaging->value,
                'sale_scheme_id' => null,
                'unit_price' => '0.0000',
                'gross_amount' => '0.0000',
                'discount_percent' => '0.00000000',
                'discount_amount' => '0.0000',
                'tax_percent' => '0.00000000',
                'tax_amount' => '0.0000',
                'line_total' => '0.0000',
                'notes' => 'Free packaging',
            ];
        }

        foreach ($offers['scheme_lines'] as $scheme) {
            /** @var Product $product */
            $product = $scheme['product'];
            $unit = $this->baseUnitForProduct($product);
            $quantity = $this->normalizeQuantity((string) $scheme['qty']);

            $lines[] = [
                'product' => $product,
                'unit' => $unit,
                'barcode' => null,
                'conversion_factor' => '1.00000000',
                'quantity' => $quantity,
                'stock_quantity' => $quantity,
                'price_type' => null,
                'line_kind' => SaleLineKind::FreeScheme->value,
                'sale_scheme_id' => $scheme['scheme']->id,
                'unit_price' => '0.0000',
                'gross_amount' => '0.0000',
                'discount_percent' => '0.00000000',
                'discount_amount' => '0.0000',
                'tax_percent' => '0.00000000',
                'tax_amount' => '0.0000',
                'line_total' => '0.0000',
                'notes' => 'Free item from sale scheme: ' .
                    $scheme['scheme']->name,
            ];
        }

        return $lines;
    }

    /**
     * Resolve and price paid lines.
     *
     * Client-sent prices are ignored. The tenant's active retail price
     * is the only source of truth.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function normalizeLines(array $items, PriceType $priceType): array
    {
        $lines = [];

        foreach ($items as $index => $item) {
            $kind = (string) (
                $item['line_kind'] ?? SaleLineKind::Sale->value
            );

            if ($kind !== SaleLineKind::Sale->value) {
                throw ValidationException::withMessages([
                    "items.$index.line_kind" =>
                        'Free lines are added through packaging and sale schemes.',
                ]);
            }

            [
                'product' => $product,
                'unit' => $unit,
                'barcode' => $barcode,
                'conversion_factor' => $conversionFactor,
            ] = $this->resolveSaleSelection($item, $index);

            if (
                $product->status !== ProductStatus::Active ||
                ! $product->is_active
            ) {
                throw ValidationException::withMessages([
                    "items.$index.product_ulid" =>
                        'This product is not available for sale.',
                ]);
            }

            $quantity = $this->normalizeQuantity(
                (string) $item['quantity']
            );

            if (! $unit->allows_decimal && $this->hasFraction($quantity)) {
                throw ValidationException::withMessages([
                    "items.$index.quantity" =>
                        'This unit does not allow decimal quantities.',
                ]);
            }

            $stockQuantity = bcmul(
                $quantity,
                $conversionFactor,
                6
            );

            if (bccomp($stockQuantity, '0', 6) <= 0) {
                throw ValidationException::withMessages([
                    "items.$index.quantity" =>
                        'Converted stock quantity must be greater than zero.',
                ]);
            }

            $baseUnitPrice = $this->resolveUnitPrice(
                $product,
                $priceType,
                $index
            );

            // ProductPrice is stored at base-unit level. A selected pack/carton
            // therefore carries the base price multiplied by its conversion factor.
            $unitPrice = bcmul(
                $baseUnitPrice,
                $conversionFactor,
                4
            );

            $grossAmount = bcmul(
                $quantity,
                $unitPrice,
                4
            );

            [
                'percent' => $discountPercent,
                'amount' => $discountAmount,
            ] = $this->resolveLineDiscount($item, $grossAmount, $index);

            $taxableAmount = bcsub(
                $grossAmount,
                $discountAmount,
                4
            );

            $taxPercent = $product->is_taxable
                ? $this->normalizePercent(
                    (string) ($product->tax_percent ?? '0'),
                    "items.$index.tax_percent"
                )
                : '0.00000000';

            $taxAmount = bcdiv(
                bcmul($taxableAmount, $taxPercent, 8),
                '100',
                4
            );

            $lineTotal = bcadd(
                $taxableAmount,
                $taxAmount,
                4
            );

            $lines[] = [
                'product' => $product,
                'unit' => $unit,
                'barcode' => $barcode,
                'conversion_factor' => $conversionFactor,
                'quantity' => $quantity,
                'stock_quantity' => $stockQuantity,
                'price_type' => $priceType->value,
                'line_kind' => SaleLineKind::Sale->value,
                'sale_scheme_id' => null,
                'unit_price' => $unitPrice,
                'gross_amount' => $grossAmount,
                'discount_percent' => $discountPercent,
                'discount_amount' => $discountAmount,
                'tax_percent' => $taxPercent,
                'tax_amount' => $taxAmount,
                'line_total' => $lineTotal,
                'notes' => $item['notes'] ?? null,
            ];
        }

        return $lines;
    }

    /**
     * Resolve a line discount from either percentage or fixed amount.
     * The client may edit either Disc % or Disc Rs, but not both at once.
     *
     * @param array<string, mixed> $item
     * @return array{percent: string, amount: string}
     */
    private function resolveLineDiscount(
        array $item,
        string $grossAmount,
        int $index
    ): array {
        $percentRaw = trim((string) ($item['discount_percent'] ?? ''));
        $amountRaw = trim((string) ($item['discount_amount'] ?? ''));

        $percent = $percentRaw === ''
            ? '0.00000000'
            : $this->normalizePercent(
                $percentRaw,
                "items.$index.discount_percent"
            );

        $amount = $amountRaw === ''
            ? '0.0000'
            : $this->normalizeMoney(
                $amountRaw,
                "items.$index.discount_amount"
            );

        if (
            bccomp($percent, '0', 8) === 1 &&
            bccomp($amount, '0', 4) === 1
        ) {
            throw ValidationException::withMessages([
                "items.$index.discount_amount" =>
                    'Enter either Disc % or Disc Rs, not both.',
            ]);
        }

        if (bccomp($percent, '0', 8) === 1) {
            $amount = bcdiv(
                bcmul($grossAmount, $percent, 8),
                '100',
                4
            );
        } elseif (bccomp($amount, '0', 4) === 1) {
            if (bccomp($amount, $grossAmount, 4) === 1) {
                throw ValidationException::withMessages([
                    "items.$index.discount_amount" =>
                        'Discount amount cannot exceed the line amount.',
                ]);
            }

            if (bccomp($grossAmount, '0', 4) === 1) {
                $percent = bcdiv(
                    bcmul($amount, '100', 8),
                    $grossAmount,
                    8
                );
            }
        }

        if (bccomp($amount, $grossAmount, 4) === 1) {
            throw ValidationException::withMessages([
                "items.$index.discount_amount" =>
                    'Discount amount cannot exceed the line amount.',
            ]);
        }

        return [
            'percent' => $percent,
            'amount' => $amount,
        ];
    }

    private function normalizePercent(string $value, string $field): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,8})?$/', $value)) {
            throw ValidationException::withMessages([
                $field => 'Percentage must be a valid non-negative decimal with up to 8 places.',
            ]);
        }

        $normalized = bcadd($value, '0', 8);

        if (bccomp($normalized, '100', 8) === 1) {
            throw ValidationException::withMessages([
                $field => 'Percentage cannot exceed 100.',
            ]);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $item
     * @return array{
     *     product: Product,
     *     unit: Unit,
     *     barcode: string|null,
     *     conversion_factor: string
     * }
     */
    private function resolveSaleSelection(array $item, int $index): array
    {
        $productUlid = trim((string) ($item['product_ulid'] ?? ''));
        $barcodeValue = trim((string) ($item['barcode'] ?? ''));
        $unitUlid = trim((string) ($item['unit_ulid'] ?? ''));

        if ($productUlid === '' && $barcodeValue === '') {
            throw ValidationException::withMessages([
                "items.$index.product_ulid" =>
                    'Select a product or scan a barcode.',
            ]);
        }

        if ($barcodeValue !== '') {
            $barcode = ProductBarcode::query()
                ->forTenant($this->tenantContext->tenantId())
                ->with(['product', 'unit'])
                ->where('barcode', $barcodeValue)
                ->where('is_active', true)
                ->first();

            if (! $barcode || ! $barcode->product || ! $barcode->unit) {
                throw ValidationException::withMessages([
                    "items.$index.barcode" =>
                        'This barcode is not active for the current tenant.',
                ]);
            }

            $product = $barcode->product;
            $unit = $barcode->unit;

            if ($productUlid !== '' && $product->ulid !== $productUlid) {
                throw ValidationException::withMessages([
                    "items.$index.barcode" =>
                        'The scanned barcode does not belong to the selected product.',
                ]);
            }

            if ($unitUlid !== '' && $unit->ulid !== $unitUlid) {
                throw ValidationException::withMessages([
                    "items.$index.unit_ulid" =>
                        'The selected unit does not match the scanned barcode.',
                ]);
            }

            $this->assertUnitAvailable($unit, $index);

            return [
                'product' => $product,
                'unit' => $unit,
                'barcode' => $barcode->barcode,
                'conversion_factor' => $this->normalizeFactor(
                    (string) $barcode->conversion_factor,
                    "items.$index.barcode"
                ),
            ];
        }

        $product = $this->catalog->product($productUlid);

        if ($unitUlid === '') {
            $unit = $this->baseUnitForProduct($product);

            return [
                'product' => $product,
                'unit' => $unit,
                'barcode' => null,
                'conversion_factor' => '1.00000000',
            ];
        }

        $unit = Unit::query()
            ->forTenant($this->tenantContext->tenantId())
            ->where('ulid', $unitUlid)
            ->first();

        if (! $unit) {
            throw ValidationException::withMessages([
                "items.$index.unit_ulid" => 'The selected unit was not found.',
            ]);
        }

        $this->assertUnitAvailable($unit, $index);

        if ((int) $unit->id === (int) $product->base_unit_id) {
            $factor = '1.00000000';
        } elseif (
            $product->secondary_unit_id !== null &&
            (int) $unit->id === (int) $product->secondary_unit_id
        ) {
            $factor = $this->normalizeFactor(
                (string) $product->secondary_conversion_factor,
                "items.$index.unit_ulid"
            );
        } else {
            throw ValidationException::withMessages([
                "items.$index.unit_ulid" =>
                    'This unit is not configured for the selected product.',
            ]);
        }

        return [
            'product' => $product,
            'unit' => $unit,
            'barcode' => null,
            'conversion_factor' => $factor,
        ];
    }

    private function resolveUnitPrice(
        Product $product,
        PriceType $priceType,
        int $index
    ): string {
        $price = ProductPrice::query()
            ->forTenant($this->tenantContext->tenantId())
            ->where('product_id', $product->id)
            ->where('price_type', $priceType->value)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query
                    ->whereNull('effective_from')
                    ->orWhereDate(
                        'effective_from',
                        '<=',
                        today()
                    );
            })
            ->where(function ($query): void {
                $query
                    ->whereNull('effective_to')
                    ->orWhereDate(
                        'effective_to',
                        '>=',
                        today()
                    );
            })
            ->orderByDesc('effective_from')
            ->value('amount');

        if ($price === null) {
            throw ValidationException::withMessages([
                "items.$index.product_ulid" =>
                    'This product has no active '.$priceType->value.' price.',
            ]);
        }

        return $this->normalizeMoney(
            (string) $price,
            "items.$index.unit_price"
        );
    }

    private function resolvePriceType(mixed $value): PriceType
    {
        $value = is_string($value) ? trim($value) : '';

        return match ($value) {
            '', 'default', PriceType::Retail->value => PriceType::Retail,
            PriceType::Wholesale->value => PriceType::Wholesale,
            default => throw ValidationException::withMessages([
                'price_type' => 'Price type must be default, retail, or wholesale.',
            ]),
        };
    }

    private function baseUnitForProduct(Product $product): Unit
    {
        $unit = Unit::query()
            ->forTenant($this->tenantContext->tenantId())
            ->whereKey($product->base_unit_id)
            ->first();

        if (! $unit || ! $unit->is_active) {
            throw ValidationException::withMessages([
                'items' => 'The product base unit is not available for sale.',
            ]);
        }

        return $unit;
    }

    private function assertUnitAvailable(Unit $unit, int $index): void
    {
        if (! $unit->is_active) {
            throw ValidationException::withMessages([
                "items.$index.unit_ulid" =>
                    'The selected unit is not active.',
            ]);
        }
    }

    private function normalizeFactor(string $value, string $field): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,8})?$/', $value)) {
            throw ValidationException::withMessages([
                $field => 'Conversion factor must be a valid decimal with up to 8 places.',
            ]);
        }

        $normalized = bcadd($value, '0', 8);

        if (bccomp($normalized, '0', 8) <= 0) {
            throw ValidationException::withMessages([
                $field => 'Conversion factor must be greater than zero.',
            ]);
        }

        return $normalized;
    }

    private function hasFraction(string $value): bool
    {
        $parts = explode('.', $value, 2);

        return isset($parts[1]) && trim($parts[1], '0') !== '';
    }

    private function resolveWarehouse(?string $ulid): Warehouse
    {
        if (is_string($ulid) && $ulid !== '') {
            $warehouse = $this->catalog->warehouse($ulid);
        } else {
            $warehouse = $this->tenantContext->warehouse();
        }

        if ($warehouse->status !== WarehouseStatus::Active) {
            throw ValidationException::withMessages([
                'warehouse_ulid' =>
                    'Sales cannot be posted to an inactive warehouse.',
            ]);
        }

        if (
            (int) $warehouse->branch_id !==
            $this->tenantContext->branchId()
        ) {
            throw ValidationException::withMessages([
                'warehouse_ulid' =>
                    'This warehouse is not available at your branch.',
            ]);
        }

        return $warehouse;
    }

    private function resolveSalesman(mixed $ulid): ?PartyProfile
    {
        $ulid = is_string($ulid) ? trim($ulid) : '';

        if ($ulid === '') {
            return null;
        }

        $profile = PartyProfile::query()
            ->forTenant($this->tenantContext->tenantId())
            ->where('ulid', $ulid)
            ->where('is_active', true)
            ->whereHas('types', fn ($q) => $q->where('type', 'salesman'))
            ->first();

        if (! $profile) {
            throw ValidationException::withMessages([
                'salesman_ulid' => 'The selected salesman is not active for this tenant.',
            ]);
        }

        return $profile;
    }

    private function resolveCustomer(?string $ulid): ?Customer
    {
        if (! is_string($ulid) || $ulid === '') {
            return null;
        }

        $customer = $this->catalog->customer($ulid);

        if (! $customer->is_active) {
            throw ValidationException::withMessages([
                'customer_ulid' =>
                    'This customer is not active.',
            ]);
        }

        if ($customer->invoice_restricted) {
            throw ValidationException::withMessages([
                'customer_ulid' =>
                    'Sales invoices are restricted for this customer.',
            ]);
        }

        return $customer;
    }

    private function nextDocumentNumber(int $tenantId): string
    {
        $latest = Sale::query()
            ->forTenant($tenantId)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('document_number');

        $seq = 1;

        if (
            is_string($latest) &&
            preg_match(
                '/SAL-(\d+)$/',
                $latest,
                $matches
            ) === 1
        ) {
            $seq = (int) $matches[1] + 1;
        }

        return 'SAL-' .
            str_pad(
                (string) $seq,
                6,
                '0',
                STR_PAD_LEFT
            );
    }

    private function normalizeQuantity(string $value): string
    {
        if (
            ! preg_match(
                '/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/',
                $value
            )
        ) {
            throw ValidationException::withMessages([
                'items' =>
                    'Quantity must be a valid decimal with up to 6 places.',
            ]);
        }

        $normalized = bcadd(
            $value,
            '0',
            6
        );

        if (
            bccomp(
                $normalized,
                '0',
                6
            ) <= 0
        ) {
            throw ValidationException::withMessages([
                'items' =>
                    'Quantity must be greater than zero.',
            ]);
        }

        return $normalized;
    }

    private function normalizeMoney(
        string $value,
        string $field
    ): string {
        if (
            ! preg_match(
                '/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/',
                $value
            )
        ) {
            throw ValidationException::withMessages([
                $field =>
                    'Amount must be a valid non-negative decimal with up to 4 places.',
            ]);
        }

        return bcadd(
            $value,
            '0',
            4
        );
    }
}
