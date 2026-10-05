<?php

namespace App\Actions\Sales;

use App\Enums\SaleLineKind;
use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Models\PartyProfile;
use App\Models\Product;
use App\Models\SaleHold;
use App\Models\SaleHoldItem;
use App\Models\SaleScheme;
use App\Models\Unit;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreSaleHoldAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function execute(array $data, string $idempotencyKey): SaleHold
    {
        $tenantId = $this->tenantContext->tenantId();
        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
            throw new ApiException(
                'IDEMPOTENCY_KEY_REQUIRED',
                'A valid Idempotency-Key header is required to hold a sale.',
                422,
            );
        }

        return DB::transaction(function () use ($data, $idempotencyKey, $tenantId): SaleHold {
            $existing = SaleHold::query()
                ->forTenant($tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $this->load($existing);
            }

            $customer = $this->resolveCustomer($data['customer_ulid'] ?? null);
            $salesman = $this->resolveSalesman($data['salesman_ulid'] ?? null);
            $lines = $this->normalizeLines($data['lines'] ?? []);

            if (! collect($lines)->contains(fn (array $line): bool => $line['line_kind'] === SaleLineKind::Sale->value)) {
                throw ValidationException::withMessages([
                    'lines' => 'An on-hold sale needs at least one paid sale line.',
                ]);
            }

            $hold = SaleHold::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $this->tenantContext->branchId(),
                'warehouse_id' => $this->tenantContext->warehouseId(),
                'customer_id' => $customer?->id,
                'salesman_party_profile_id' => $salesman?->id,
                'sale_date' => $data['sale_date'] ?? now()->toDateString(),
                'price_type' => (string) ($data['price_type'] ?? 'default'),
                'notes' => $data['notes'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $this->tenantContext->userId(),
                'updated_by' => $this->tenantContext->userId(),
            ]);

            foreach ($lines as $index => $line) {
                SaleHoldItem::query()->create([
                    'tenant_id' => $tenantId,
                    'sale_hold_id' => $hold->id,
                    'product_id' => $line['product_id'],
                    'unit_id' => $line['unit_id'],
                    'sale_scheme_id' => $line['sale_scheme_id'],
                    'line_kind' => $line['line_kind'],
                    'barcode' => $line['barcode'],
                    'quantity' => $line['quantity'],
                    'discount_percent' => $line['discount_percent'],
                    'discount_amount' => $line['discount_amount'],
                    'notes' => $line['notes'],
                    'sort_order' => $index,
                ]);
            }

            $this->audit->record('SALE_HOLD_CREATED', [
                'resource_type' => 'sale_hold',
                'resource_ulid' => $hold->ulid,
                'line_count' => count($lines),
            ]);

            return $this->load($hold);
        });
    }

    /**
     * @param array<int, mixed> $rawLines
     * @return list<array<string, mixed>>
     */
    private function normalizeLines(array $rawLines): array
    {
        $tenantId = $this->tenantContext->tenantId();

        $productUlids = collect($rawLines)
            ->pluck('product_ulid')
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->unique()
            ->values();

        $products = Product::query()
            ->forTenant($tenantId)
            ->where('is_active', true)
            ->whereIn('ulid', $productUlids)
            ->with(['barcodes'])
            ->get()
            ->keyBy('ulid');

        $unitUlids = collect($rawLines)
            ->pluck('unit_ulid')
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->unique()
            ->values();

        $units = Unit::query()
            ->forTenant($tenantId)
            ->where('is_active', true)
            ->whereIn('ulid', $unitUlids)
            ->get()
            ->keyBy('ulid');

        $schemeUlids = collect($rawLines)
            ->pluck('scheme_ulid')
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->unique()
            ->values();

        $schemes = SaleScheme::query()
            ->forTenant($tenantId)
            ->where('is_active', true)
            ->whereIn('ulid', $schemeUlids)
            ->get()
            ->keyBy('ulid');

        $lines = [];

        foreach ($rawLines as $index => $raw) {
            $productUlid = (string) ($raw['product_ulid'] ?? '');
            $product = $products->get($productUlid);

            if (! $product) {
                throw ValidationException::withMessages([
                    "lines.$index.product_ulid" => 'The selected product is not active for this tenant.',
                ]);
            }

            $kind = SaleLineKind::tryFrom((string) ($raw['line_kind'] ?? ''));
            if (! $kind) {
                throw ValidationException::withMessages([
                    "lines.$index.line_kind" => 'The selected line type is invalid.',
                ]);
            }

            $unit = null;
            $unitUlid = trim((string) ($raw['unit_ulid'] ?? ''));
            if ($unitUlid !== '') {
                $unit = $units->get($unitUlid);

                if (! $unit || ! $this->productUsesUnit($product, (int) $unit->id)) {
                    throw ValidationException::withMessages([
                        "lines.$index.unit_ulid" => 'The selected unit is not available for this product.',
                    ]);
                }
            }

            $barcode = trim((string) ($raw['barcode'] ?? ''));
            if ($barcode !== '') {
                $barcodeRow = $product->barcodes
                    ->first(fn ($row) => $row->is_active && $row->barcode === $barcode);

                if (! $barcodeRow) {
                    throw ValidationException::withMessages([
                        "lines.$index.barcode" => 'The selected barcode is not active for this product.',
                    ]);
                }

                if ($barcodeRow->unit_id !== null) {
                    if ($unit && (int) $unit->id !== (int) $barcodeRow->unit_id) {
                        throw ValidationException::withMessages([
                            "lines.$index.unit_ulid" => 'The selected unit does not match the barcode unit.',
                        ]);
                    }

                    if (! $unit) {
                        $unit = Unit::query()
                            ->forTenant($tenantId)
                            ->whereKey($barcodeRow->unit_id)
                            ->where('is_active', true)
                            ->first();
                    }
                }
            }

            $scheme = null;
            $schemeUlid = trim((string) ($raw['scheme_ulid'] ?? ''));

            if ($kind === SaleLineKind::FreeScheme) {
                $scheme = $schemes->get($schemeUlid);

                if (! $scheme || (int) $scheme->reward_product_id !== (int) $product->id) {
                    throw ValidationException::withMessages([
                        "lines.$index.scheme_ulid" => 'The selected sale scheme is not valid for this reward product.',
                    ]);
                }
            } elseif ($schemeUlid !== '') {
                throw ValidationException::withMessages([
                    "lines.$index.scheme_ulid" => 'Only free scheme lines may reference a sale scheme.',
                ]);
            }

            if ($kind === SaleLineKind::FreePackaging && ! $product->is_packaging) {
                throw ValidationException::withMessages([
                    "lines.$index.product_ulid" => 'The selected free packaging line is not a packaging product.',
                ]);
            }

            $discountPercent = bcadd((string) ($raw['discount_percent'] ?? '0'), '0', 8);
            $discountAmount = bcadd((string) ($raw['discount_amount'] ?? '0'), '0', 4);

            if ($kind !== SaleLineKind::Sale) {
                $discountPercent = '0.00000000';
                $discountAmount = '0.0000';
            } elseif (
                bccomp($discountPercent, '0', 8) > 0 &&
                bccomp($discountAmount, '0', 4) > 0
            ) {
                throw ValidationException::withMessages([
                    "lines.$index.discount_amount" => 'Use either discount percent or discount amount, not both.',
                ]);
            }

            $lines[] = [
                'product_id' => $product->id,
                'unit_id' => $unit?->id,
                'sale_scheme_id' => $scheme?->id,
                'line_kind' => $kind->value,
                'barcode' => $barcode !== '' ? $barcode : null,
                'quantity' => bcadd((string) $raw['quantity'], '0', 6),
                'discount_percent' => $discountPercent,
                'discount_amount' => $discountAmount,
                'notes' => $raw['notes'] ?? null,
            ];
        }

        return $lines;
    }

    private function productUsesUnit(Product $product, int $unitId): bool
    {
        if ((int) $product->base_unit_id === $unitId) {
            return true;
        }

        if ($product->secondary_unit_id !== null && (int) $product->secondary_unit_id === $unitId) {
            return true;
        }

        return $product->barcodes->contains(
            fn ($barcode) => $barcode->is_active && (int) $barcode->unit_id === $unitId
        );
    }

    private function resolveCustomer(mixed $ulid): ?Customer
    {
        $ulid = is_string($ulid) ? trim($ulid) : '';
        if ($ulid === '') return null;

        $customer = Customer::query()
            ->forTenant($this->tenantContext->tenantId())
            ->where('ulid', $ulid)
            ->where('is_active', true)
            ->first();

        if (! $customer) {
            throw ValidationException::withMessages([
                'customer_ulid' => 'The selected customer is not active for this tenant.',
            ]);
        }

        return $customer;
    }

    private function resolveSalesman(mixed $ulid): ?PartyProfile
    {
        $ulid = is_string($ulid) ? trim($ulid) : '';
        if ($ulid === '') return null;

        $profile = PartyProfile::query()
            ->forTenant($this->tenantContext->tenantId())
            ->where('ulid', $ulid)
            ->where('is_active', true)
            ->whereHas('types', fn ($query) => $query->where('type', 'salesman'))
            ->first();

        if (! $profile) {
            throw ValidationException::withMessages([
                'salesman_ulid' => 'The selected salesman is not active for this tenant.',
            ]);
        }

        return $profile;
    }

    private function load(SaleHold $hold): SaleHold
    {
        return $hold->load([
            'customer',
            'salesmanParty',
            'branch',
            'warehouse',
            'items' => fn ($query) => $query->orderBy('sort_order'),
            'items.product.baseUnit',
            'items.product.secondaryUnit',
            'items.product.barcodes.unit',
            'items.product.prices',
            'items.unit',
            'items.saleScheme',
        ]);
    }
}
