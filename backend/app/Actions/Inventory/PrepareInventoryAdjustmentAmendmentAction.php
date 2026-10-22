<?php

namespace App\Actions\Inventory;

use App\Enums\ProductStatus;
use App\Enums\WarehouseStatus;
use App\Exceptions\ApiException;
use App\Models\Account;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\StockBalance;
use App\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

class PrepareInventoryAdjustmentAmendmentAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $before
     * @return array{header:array<string,mixed>,lines:list<array<string,mixed>>}
     */
    public function execute(
        InventoryAdjustment $document,
        array $data,
        array $before,
    ): array {
        $tenantId = $this->tenantContext->tenantId();
        $warehouse = $this->tenantContext->warehouse();

        if ($warehouse->status !== WarehouseStatus::Active) {
            throw ValidationException::withMessages([
                'warehouse' => 'Inventory adjustment cannot use an inactive warehouse.',
            ]);
        }

        if ((int) $warehouse->id !== (int) $document->warehouse_id) {
            throw new ApiException(
                'NOT_FOUND',
                'Switch to the adjustment warehouse before amending this document.',
                404,
            );
        }

        $account = Account::query()
            ->forTenant($tenantId)
            ->where('ulid', (string) ($data['account_ulid'] ?? ''))
            ->where('is_active', true)
            ->first();

        if (! $account) {
            throw new ApiException('NOT_FOUND', 'The selected adjustment account was not found.', 404);
        }

        $beforeByProduct = [];
        foreach (($before['lines'] ?? []) as $line) {
            $productUlid = (string) ($line['product']['ulid'] ?? '');
            if ($productUlid !== '') {
                $beforeByProduct[$productUlid] = $line;
            }
        }

        $seen = [];
        $lines = [];

        foreach (($data['lines'] ?? []) as $index => $item) {
            $productUlid = trim((string) ($item['product_ulid'] ?? ''));
            if ($productUlid === '') {
                throw ValidationException::withMessages([
                    "lines.$index.product_ulid" => 'Product is required.',
                ]);
            }

            if (isset($seen[$productUlid])) {
                throw ValidationException::withMessages([
                    "lines.$index.product_ulid" => 'The same product cannot appear more than once.',
                ]);
            }
            $seen[$productUlid] = true;

            $product = Product::query()
                ->forTenant($tenantId)
                ->where('ulid', $productUlid)
                ->where('status', ProductStatus::Active->value)
                ->where('is_active', true)
                ->first();

            if (! $product) {
                throw ValidationException::withMessages([
                    "lines.$index.product_ulid" => 'The selected product is unavailable.',
                ]);
            }

            $delta = $this->signedQuantity(
                $item['qty_plus'] ?? null,
                $item['qty_minus'] ?? null,
                $index,
            );

            $previous = $beforeByProduct[$productUlid] ?? null;
            $unitCost = $previous
                ? bcadd((string) ($previous['price'] ?? '0'), '0', 4)
                : bcadd((string) (
                    StockBalance::query()
                        ->where('tenant_id', $tenantId)
                        ->where('warehouse_id', $warehouse->id)
                        ->where('product_id', $product->id)
                        ->value('average_cost') ?? '0'
                ), '0', 4);

            $absoluteQty = bccomp($delta, '0', 6) < 0
                ? bcsub('0.000000', $delta, 6)
                : $delta;
            $amount = bcmul($absoluteQty, $unitCost, 4);

            $lines[] = [
                'product_model' => $product,
                'product' => [
                    'ulid' => $product->ulid,
                    'product_number' => $product->product_number,
                    'sku' => $product->sku,
                    'name' => $product->name,
                ],
                'quantity_delta' => $delta,
                'qty_plus' => bccomp($delta, '0', 6) > 0 ? $delta : '0.000000',
                'qty_minus' => bccomp($delta, '0', 6) < 0 ? bcsub('0.000000', $delta, 6) : '0.000000',
                'price' => $unitCost,
                'amount' => $amount,
                'remarks' => $item['notes'] ?? null,
            ];
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'Add at least one adjustment line.',
            ]);
        }

        return [
            'header' => [
                'adjustment_date' => (string) ($data['adjustment_date'] ?? $before['adjustment_date'] ?? now()->toDateString()),
                'notes' => $data['notes'] ?? ($before['notes'] ?? null),
                'account_model' => $account,
                'account' => [
                    'ulid' => $account->ulid,
                    'code' => $account->code,
                    'name' => $account->name,
                ],
                'warehouse' => [
                    'ulid' => $warehouse->ulid,
                    'code' => $warehouse->code,
                    'name' => $warehouse->name,
                ],
            ],
            'lines' => $lines,
        ];
    }

    private function signedQuantity(?string $plus, ?string $minus, int $index): string
    {
        $plus = $this->normalizeOptional($plus, "lines.$index.qty_plus");
        $minus = $this->normalizeOptional($minus, "lines.$index.qty_minus");

        $hasPlus = bccomp($plus, '0', 6) > 0;
        $hasMinus = bccomp($minus, '0', 6) > 0;

        if ($hasPlus === $hasMinus) {
            throw ValidationException::withMessages([
                "lines.$index.quantity" => 'Enter either Qty + or Qty - with a value greater than zero.',
            ]);
        }

        return $hasPlus ? $plus : bcsub('0.000000', $minus, 6);
    }

    private function normalizeOptional(?string $value, string $field): string
    {
        $value = $value === null || $value === '' ? '0' : trim($value);

        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/', $value)) {
            throw ValidationException::withMessages([
                $field => 'Quantity must be a non-negative decimal with up to 6 places.',
            ]);
        }

        return bcadd($value, '0', 6);
    }
}
