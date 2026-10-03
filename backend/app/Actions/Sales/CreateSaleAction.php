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
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Warehouse;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a posted sale.
 *
 * Every money figure is recalculated server-side with BCMath; the payload's
 * client totals are never trusted. Free packaging and free scheme lines are
 * priced at 0 but still consume stock, so stock goes out for every line.
 *
 * Requires an Idempotency-Key: replaying the same key returns the original sale
 * instead of creating a second one.
 */
class CreateSaleAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantCatalog $catalog,
        private readonly ValidateAppliedSaleOffersAction $validateOffers,
        private readonly PostStockMovementAction $postMovement,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
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

        return DB::transaction(function () use ($data, $idempotencyKey, $tenantId): Sale {
            $existing = Sale::query()
                ->forTenant($tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing->fresh(static::with()) ?? $existing;
            }

            $warehouse = $this->resolveWarehouse($data['warehouse_ulid'] ?? null);
            $customer = $this->resolveCustomer($data['customer_ulid'] ?? null);
            $lines = $this->normalizeLines($data['items'] ?? []);

            if ($lines === []) {
                throw ValidationException::withMessages([
                    'items' => 'Add at least one sale line.',
                ]);
            }

            $paidLines = array_values(array_filter(
                $lines,
                static fn (array $line): bool => $line['line_kind'] === SaleLineKind::Sale->value,
            ));

            if ($paidLines === []) {
                throw ValidationException::withMessages([
                    'items' => 'A sale needs at least one paid line.',
                ]);
            }

            // Paid lines first: subtotal is derived from paid lines only, so the
            // scheme threshold is judged on what the customer actually bought.
            $paidSubtotal = '0.0000';
            foreach ($paidLines as $line) {
                $paidSubtotal = bcadd($paidSubtotal, $line['line_total'], 4);
            }

            $offers = $this->validateOffers->execute(
                $paidSubtotal,
                $data['free_lines'] ?? [],
                $data['applied_scheme_ulids'] ?? [],
                $data['sale_date'] ?? null,
            );

            // A free line the salesman sent without a scheme is never honoured.
            $lines = array_merge($paidLines, $this->offersToLines($offers));

            $this->persist($lines, $warehouse, $customer, $data, $idempotencyKey, $tenantId);

            $sale = Sale::query()
                ->forTenant($tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();

            $this->audit->record('SALE_POSTED', [
                'resource_type' => 'sale',
                'resource_ulid' => $sale->ulid,
                'line_count' => count($lines),
            ]);

            return $sale->fresh(static::with()) ?? $sale;
        });
    }

    /**
     * @return list<string>
     */
    public static function with(): array
    {
        return ['customer', 'branch', 'warehouse', 'items.product', 'items.unit', 'items.saleScheme'];
    }

    /**
     * @param  list<array<string, mixed>>  $offersLines
     * @param  array<string, mixed>  $data
     */
    private function persist(
        array $offersLines,
        Warehouse $warehouse,
        ?Customer $customer,
        array $data,
        string $idempotencyKey,
        int $tenantId,
    ): void {
        $subtotal = '0.0000';
        $discountTotal = '0.0000';
        $taxTotal = '0.0000';

        $sale = Sale::query()->create([
            'tenant_id' => $tenantId,
            'branch_id' => $warehouse->branch_id,
            'warehouse_id' => $warehouse->id,
            'customer_id' => $customer?->id,
            'document_number' => $this->nextDocumentNumber($tenantId),
            'status' => SaleStatus::Posted,
            'sale_date' => $data['sale_date'] ?? now()->toDateString(),
            'subtotal' => '0.0000',
            'discount_amount' => '0.0000',
            'tax_amount' => '0.0000',
            'grand_total' => '0.0000',
            'notes' => $data['notes'] ?? null,
            'idempotency_key' => $idempotencyKey,
            'created_by' => $this->tenantContext->userId(),
            'posted_at' => now(),
        ]);

        foreach ($offersLines as $line) {
            /** @var Product $product */
            $product = $line['product'];
            $quantity = $line['quantity'];

            $item = SaleItem::query()->create([
                'tenant_id' => $tenantId,
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'unit_id' => $product->base_unit_id,
                'line_kind' => $line['line_kind'],
                'sale_scheme_id' => $line['sale_scheme_id'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $line['unit_price'],
                'discount_amount' => $line['discount_amount'],
                'tax_amount' => $line['tax_amount'],
                'line_total' => $line['line_total'],
                'notes' => $line['notes'] ?? null,
            ]);

            $subtotal = bcadd($subtotal, $line['line_total'], 4);
            $discountTotal = bcadd($discountTotal, $line['discount_amount'], 4);
            $taxTotal = bcadd($taxTotal, $line['tax_amount'], 4);

            // Every line consumes stock, including free packaging and scheme rewards.
            $this->postMovement->execute([
                'warehouse' => $warehouse,
                'product' => $product,
                'movement_type' => StockMovementType::Sale,
                'quantity' => bcsub('0', $quantity, 6),
                'reference_type' => 'sale',
                'reference_ulid' => $sale->ulid,
                'reference_line_ulid' => $item->ulid,
                'occurred_at' => $sale->sale_date?->copy()->startOfDay() ?? now(),
                'idempotency_key' => 'sale:'.$sale->ulid.':'.$item->ulid,
            ]);
        }

        $sale->subtotal = $subtotal;
        $sale->discount_amount = $discountTotal;
        $sale->tax_amount = $taxTotal;
        $sale->grand_total = bcadd(bcsub($subtotal, $discountTotal, 4), $taxTotal, 4);
        $sale->save();
    }

    /**
     * Turn the validated free packaging + scheme lines into sale item rows.
     * Both are priced at zero — the reward must not change what the customer pays.
     *
     * @param  array{packaging_lines: list<array<string, mixed>>, scheme_lines: list<array<string, mixed>>}  $offers
     * @return list<array<string, mixed>>
     */
    private function offersToLines(array $offers): array
    {
        $lines = [];

        foreach ($offers['packaging_lines'] as $packaging) {
            $lines[] = [
                'product' => $packaging['product'],
                'quantity' => $packaging['qty'],
                'line_kind' => SaleLineKind::FreePackaging->value,
                'sale_scheme_id' => null,
                'unit_price' => '0.0000',
                'discount_amount' => '0.0000',
                'tax_amount' => '0.0000',
                'line_total' => '0.0000',
                'notes' => 'Free packaging',
            ];
        }

        foreach ($offers['scheme_lines'] as $scheme) {
            $lines[] = [
                'product' => $scheme['product'],
                'quantity' => $scheme['qty'],
                'line_kind' => SaleLineKind::FreeScheme->value,
                'sale_scheme_id' => $scheme['scheme']->id,
                'unit_price' => '0.0000',
                'discount_amount' => '0.0000',
                'tax_amount' => '0.0000',
                'line_total' => '0.0000',
                'notes' => 'Free item from sale scheme: '.$scheme['scheme']->name,
            ];
        }

        return $lines;
    }

    /**
     * Resolve and price the paid lines. Client-sent prices are ignored; the
     * tenant's own retail price list is the only source of truth.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function normalizeLines(array $items): array
    {
        $lines = [];
        $seen = [];

        foreach ($items as $index => $item) {
            $kind = (string) ($item['line_kind'] ?? SaleLineKind::Sale->value);
            if ($kind !== SaleLineKind::Sale->value) {
                throw ValidationException::withMessages([
                    "items.$index.line_kind" => 'Free lines are added through packaging and sale schemes.',
                ]);
            }

            $product = $this->catalog->product((string) $item['product_ulid']);
            if ($product->status !== ProductStatus::Active || ! $product->is_active) {
                throw ValidationException::withMessages([
                    "items.$index.product_ulid" => 'This product is not available for sale.',
                ]);
            }

            if (isset($seen[$product->id])) {
                throw ValidationException::withMessages([
                    "items.$index.product_ulid" => 'The same product can only appear on one sale line.',
                ]);
            }
            $seen[$product->id] = true;

            $quantity = $this->normalizeQuantity((string) $item['quantity']);
            $unitPrice = $this->resolveUnitPrice($product, $this->tenantContext->tenant()->currency_code);
            $lineTotal = bcmul($quantity, $unitPrice, 4);

            $lines[] = [
                'product' => $product,
                'quantity' => $quantity,
                'line_kind' => SaleLineKind::Sale->value,
                'sale_scheme_id' => null,
                'unit_price' => $unitPrice,
                'discount_amount' => '0.0000',
                'tax_amount' => '0.0000',
                'line_total' => $lineTotal,
                'notes' => $item['notes'] ?? null,
            ];
        }

        return $lines;
    }

    private function resolveUnitPrice(Product $product, string $currency): string
    {
        $price = ProductPrice::query()
            ->forTenant($this->tenantContext->tenantId())
            ->where('product_id', $product->id)
            ->where('price_type', PriceType::Retail->value)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', today());
            })
            ->where(function ($query): void {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', today());
            })
            ->orderByDesc('effective_from')
            ->value('amount');

        if ($price === null) {
            throw ValidationException::withMessages([
                'items' => 'This product has no active retail price.',
            ]);
        }

        return $this->normalizeMoney((string) $price, 'unit_price');
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
                'warehouse_ulid' => 'Sales cannot be posted to an inactive warehouse.',
            ]);
        }

        if ((int) $warehouse->branch_id !== $this->tenantContext->branchId()) {
            throw ValidationException::withMessages([
                'warehouse_ulid' => 'This warehouse is not available at your branch.',
            ]);
        }

        return $warehouse;
    }

    private function resolveCustomer(?string $ulid): ?Customer
    {
        if (! is_string($ulid) || $ulid === '') {
            return null;
        }

        $customer = $this->catalog->customer($ulid);

        if (! $customer->is_active) {
            throw ValidationException::withMessages([
                'customer_ulid' => 'This customer is not active.',
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
        if (is_string($latest) && preg_match('/SAL-(\d+)$/', $latest, $matches) === 1) {
            $seq = (int) $matches[1] + 1;
        }

        return 'SAL-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }

    private function normalizeQuantity(string $value): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/', $value)) {
            throw ValidationException::withMessages([
                'items' => 'Quantity must be a valid decimal with up to 6 places.',
            ]);
        }

        $normalized = bcadd($value, '0', 6);
        if (bccomp($normalized, '0', 6) <= 0) {
            throw ValidationException::withMessages([
                'items' => 'Quantity must be greater than zero.',
            ]);
        }

        return $normalized;
    }

    private function normalizeMoney(string $value, string $field): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages([
                $field => 'Amount must be a valid non-negative decimal with up to 4 places.',
            ]);
        }

        return bcadd($value, '0', 4);
    }
}
