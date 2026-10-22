<?php

namespace App\Actions\Purchases;

use App\Actions\Inventory\StockLedgerLock;
use App\Enums\StockMovementType;
use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class PostPurchaseAmendmentStockDeltaAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly StockLedgerLock $ledgerLock,
    ) {}

    /**
     * @param array<string,mixed> $before
     * @param list<array<string,mixed>> $afterLines
     */
    public function execute(
        PurchaseInvoice $invoice,
        array $before,
        array $afterLines,
        int $revisionNumber,
    ): void {
        $tenantId = $this->tenantContext->tenantId();

        $beforeByProduct = [];
        foreach (($before['lines'] ?? []) as $line) {
            $productUlid = (string) ($line['product']['ulid'] ?? '');
            if ($productUlid === '') {
                continue;
            }

            $conversion = bcadd((string) ($line['conversion_factor'] ?? '1'), '0', 8);
            $baseQty = bcadd((string) ($line['base_quantity'] ?? '0'), '0', 6);
            $freePcs = bcadd((string) ($line['free_pcs'] ?? '0'), '0', 6);
            $freeBase = bcmul($freePcs, $conversion, 6);
            $stockQty = bcadd($baseQty, $freeBase, 6);
            $value = bcmul(
                bcadd((string) ($line['quantity'] ?? '0'), '0', 6),
                bcadd((string) ($line['unit_cost'] ?? '0'), '0', 4),
                4,
            );

            $beforeByProduct[$productUlid] ??= [
                'quantity' => '0.000000',
                'value' => '0.0000',
            ];
            $beforeByProduct[$productUlid]['quantity'] = bcadd(
                $beforeByProduct[$productUlid]['quantity'],
                $stockQty,
                6,
            );
            $beforeByProduct[$productUlid]['value'] = bcadd(
                $beforeByProduct[$productUlid]['value'],
                $value,
                4,
            );
        }

        $afterByProduct = [];
        $products = [];
        foreach ($afterLines as $line) {
            /** @var Product $product */
            $product = $line['product'];
            $productUlid = $product->ulid;
            $products[$productUlid] = $product;

            $freeBase = bcmul(
                (string) $line['free_pcs'],
                (string) $line['conversion_factor'],
                6,
            );
            $stockQty = bcadd((string) $line['base_quantity'], $freeBase, 6);
            $value = bcmul(
                (string) $line['quantity'],
                (string) $line['unit_cost'],
                4,
            );

            $afterByProduct[$productUlid] ??= [
                'quantity' => '0.000000',
                'value' => '0.0000',
            ];
            $afterByProduct[$productUlid]['quantity'] = bcadd(
                $afterByProduct[$productUlid]['quantity'],
                $stockQty,
                6,
            );
            $afterByProduct[$productUlid]['value'] = bcadd(
                $afterByProduct[$productUlid]['value'],
                $value,
                4,
            );
        }

        $this->ledgerLock->acquire($tenantId);

        foreach (array_values(array_unique(array_merge(
            array_keys($beforeByProduct),
            array_keys($afterByProduct),
        ))) as $productUlid) {
            $beforeState = $beforeByProduct[$productUlid] ?? [
                'quantity' => '0.000000',
                'value' => '0.0000',
            ];
            $afterState = $afterByProduct[$productUlid] ?? [
                'quantity' => '0.000000',
                'value' => '0.0000',
            ];

            $deltaQty = bcsub($afterState['quantity'], $beforeState['quantity'], 6);
            $deltaValue = bcsub($afterState['value'], $beforeState['value'], 4);

            if (
                bccomp($deltaQty, '0', 6) === 0
                && bccomp($deltaValue, '0', 4) === 0
            ) {
                continue;
            }

            /** @var Product|null $product */
            $product = $products[$productUlid] ?? Product::query()
                ->forTenant($tenantId)
                ->where('ulid', $productUlid)
                ->first();

            if (! $product) {
                throw new ApiException(
                    'PURCHASE_AMENDMENT_PRODUCT_MISSING',
                    'A product from the previous purchase revision is no longer available.',
                    409,
                );
            }

            if (
                isset($beforeByProduct[$productUlid])
                && $this->hasDownstreamExternalMovement($invoice, $product)
            ) {
                throw new ApiException(
                    'PURCHASE_AMENDMENT_STOCK_ALREADY_MOVED',
                    'This product has stock activity after the posted purchase. Its received quantity or cost cannot be changed safely.',
                    409,
                );
            }

            $idempotencyKey = 'purchase-amend-stock:'.$invoice->ulid.':'.$revisionNumber.':'.$productUlid;
            $existing = StockMovement::query()
                ->forTenant($tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                continue;
            }

            $balance = StockBalance::query()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $invoice->warehouse_id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();

            if (! $balance) {
                throw new ApiException(
                    'PURCHASE_AMENDMENT_STOCK_MISSING',
                    'The current stock balance for this posted purchase is missing.',
                    409,
                );
            }

            $currentQty = bcadd((string) $balance->quantity, '0', 6);
            $currentValue = $balance->stock_value !== null
                ? bcadd((string) $balance->stock_value, '0', 4)
                : (
                    $balance->average_cost !== null
                        ? bcmul($currentQty, (string) $balance->average_cost, 4)
                        : '0.0000'
                );

            $newQty = bcadd($currentQty, $deltaQty, 6);
            $newValue = bcadd($currentValue, $deltaValue, 4);

            if (bccomp($newQty, '0', 6) < 0) {
                throw new ApiException(
                    'PURCHASE_AMENDMENT_STOCK_CONSUMED',
                    'The amended purchase would remove more stock than is currently available.',
                    409,
                );
            }

            if (bccomp($newValue, '0', 4) < 0) {
                throw new ApiException(
                    'PURCHASE_AMENDMENT_VALUE_INVALID',
                    'The amended purchase would make inventory value negative.',
                    409,
                );
            }

            if (
                bccomp($newQty, '0', 6) === 0
                && bccomp($newValue, '0', 4) !== 0
            ) {
                throw new ApiException(
                    'PURCHASE_AMENDMENT_VALUE_INVALID',
                    'Inventory value cannot remain when amended stock quantity becomes zero.',
                    409,
                );
            }

            $unitCost = bccomp($deltaQty, '0', 6) === 0
                ? null
                : bcdiv(
                    bccomp($deltaValue, '0', 4) < 0
                        ? bcsub('0.0000', $deltaValue, 4)
                        : $deltaValue,
                    bccomp($deltaQty, '0', 6) < 0
                        ? bcsub('0.000000', $deltaQty, 6)
                        : $deltaQty,
                    4,
                );

            $movement = StockMovement::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $invoice->branch_id,
                'warehouse_id' => $invoice->warehouse_id,
                'product_id' => $product->id,
                'movement_type' => StockMovementType::PurchaseAmendment,
                'quantity' => $deltaQty,
                'unit_cost' => $unitCost,
                'total_cost' => $deltaValue,
                'reference_type' => 'purchase_invoice_amendment',
                'reference_ulid' => $invoice->ulid,
                'reference_line_ulid' => null,
                'occurred_at' => now(),
                'posted_by' => $this->tenantContext->userId(),
                'idempotency_key' => $idempotencyKey,
                'notes' => 'Purchase invoice revision '.$revisionNumber,
            ]);

            $balance->quantity = $newQty;
            $balance->stock_value = $newValue;
            $balance->average_cost = bccomp($newQty, '0', 6) === 0
                ? null
                : bcdiv($newValue, $newQty, 4);
            $balance->last_movement_id = $movement->id;
            $balance->save();
        }
    }

    private function hasDownstreamExternalMovement(
        PurchaseInvoice $invoice,
        Product $product,
    ): bool {
        $firstOwnMovementId = StockMovement::query()
            ->forTenant((int) $invoice->tenant_id)
            ->where('warehouse_id', $invoice->warehouse_id)
            ->where('product_id', $product->id)
            ->where(function ($q) use ($invoice): void {
                $q->where(function ($original) use ($invoice): void {
                    $original
                        ->where('reference_type', 'purchase_invoice')
                        ->where('reference_ulid', $invoice->ulid);
                })->orWhere(function ($amendment) use ($invoice): void {
                    $amendment
                        ->where('reference_type', 'purchase_invoice_amendment')
                        ->where('reference_ulid', $invoice->ulid);
                });
            })
            ->min('id');

        if (! $firstOwnMovementId) {
            return false;
        }

        return StockMovement::query()
            ->forTenant((int) $invoice->tenant_id)
            ->where('warehouse_id', $invoice->warehouse_id)
            ->where('product_id', $product->id)
            ->where('id', '>', $firstOwnMovementId)
            ->where(function ($q) use ($invoice): void {
                $q->where('reference_type', '!=', 'purchase_invoice_amendment')
                    ->orWhere('reference_ulid', '!=', $invoice->ulid)
                    ->orWhereNull('reference_ulid');
            })
            ->exists();
    }
}
