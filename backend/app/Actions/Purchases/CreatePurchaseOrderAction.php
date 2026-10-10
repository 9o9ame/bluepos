<?php

namespace App\Actions\Purchases;

use App\Catalog\TenantCatalog;
use App\Enums\ProductStatus;
use App\Enums\WarehouseStatus;
use App\Models\ProductBarcode;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Tenant;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreatePurchaseOrderAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantCatalog $catalog,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data): PurchaseOrder {
            $tenantId = $this->tenantContext->tenantId();
            $warehouse = $this->tenantContext->warehouse();
            $supplier = $this->catalog->supplier((string) $data['supplier_ulid']);

            if (! $supplier->is_active) {
                throw ValidationException::withMessages([
                    'supplier_ulid' => 'Purchase orders cannot use an inactive supplier.',
                ]);
            }

            if ($warehouse->status !== WarehouseStatus::Active) {
                throw ValidationException::withMessages([
                    'warehouse' => 'Purchase orders cannot use an inactive warehouse.',
                ]);
            }

            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();

            $order = PurchaseOrder::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'supplier_id' => $supplier->id,
                'document_number' => $this->nextDocumentNumber($tenantId),
                'order_date' => $data['order_date'] ?? now()->toDateString(),
                'status' => 'open',
                'subtotal' => '0.0000',
                'discount_amount' => '0.0000',
                'grand_total' => '0.0000',
                'notes' => $data['notes'] ?? null,
                'created_by' => $this->tenantContext->userId(),
            ]);

            $subtotal = '0.0000';
            $discountTotal = '0.0000';
            $grandTotal = '0.0000';

            foreach ($data['items'] as $index => $item) {
                $product = $this->catalog->product((string) $item['product_ulid']);
                if ($product->status !== ProductStatus::Active || ! $product->is_active) {
                    throw ValidationException::withMessages([
                        "items.$index.product_ulid" => 'Purchase orders cannot use an inactive product.',
                    ]);
                }

                $unit = $this->catalog->unit((string) $item['unit_ulid']);
                $conversion = $this->resolveConversionFactor($product->id, $product->base_unit_id, $product->secondary_unit_id, (string) $product->secondary_conversion_factor, $unit->id);

                $quantity = $this->positiveQty((string) $item['quantity'], "items.$index.quantity");
                $unitPrice = $this->money((string) $item['unit_price'], "items.$index.unit_price");
                $discountPercent = $this->percent((string) ($item['discount_percent'] ?? '0'), "items.$index.discount_percent");
                $discountAmountInput = $this->money((string) ($item['discount_amount'] ?? '0'), "items.$index.discount_amount");

                if (bccomp($discountPercent, '0', 8) === 1 && bccomp($discountAmountInput, '0', 4) === 1) {
                    throw ValidationException::withMessages([
                        "items.$index.discount_amount" => 'Use either discount percent or discount amount, not both.',
                    ]);
                }

                $gross = bcmul($quantity, $unitPrice, 4);
                $discount = bccomp($discountPercent, '0', 8) === 1
                    ? bcdiv(bcmul($gross, $discountPercent, 8), '100', 4)
                    : $discountAmountInput;

                if (bccomp($discount, $gross, 4) === 1) {
                    throw ValidationException::withMessages([
                        "items.$index.discount_amount" => 'Discount cannot exceed the gross line amount.',
                    ]);
                }

                $lineTotal = bcsub($gross, $discount, 4);

                PurchaseOrderLine::query()->create([
                    'tenant_id' => $tenantId,
                    'purchase_order_id' => $order->id,
                    'product_id' => $product->id,
                    'unit_id' => $unit->id,
                    'quantity' => $quantity,
                    'conversion_factor' => $conversion,
                    'base_quantity' => bcmul($quantity, $conversion, 6),
                    'unit_price' => $unitPrice,
                    'gross_amount' => $gross,
                    'discount_percent' => $discountPercent,
                    'discount_amount' => $discount,
                    'line_total' => $lineTotal,
                    'notes' => $item['notes'] ?? null,
                ]);

                $subtotal = bcadd($subtotal, $gross, 4);
                $discountTotal = bcadd($discountTotal, $discount, 4);
                $grandTotal = bcadd($grandTotal, $lineTotal, 4);
            }

            $order->forceFill([
                'subtotal' => $subtotal,
                'discount_amount' => $discountTotal,
                'grand_total' => $grandTotal,
            ])->save();

            $this->audit->record('PURCHASE_ORDER_CREATED', [
                'resource_type' => 'purchase_order',
                'resource_ulid' => $order->ulid,
            ]);

            return $order->fresh(['supplier', 'branch', 'warehouse', 'lines.product', 'lines.unit']) ?? $order;
        });
    }

    private function nextDocumentNumber(int $tenantId): string
    {
        $latest = PurchaseOrder::query()
            ->forTenant($tenantId)
            ->orderByDesc('id')
            ->value('document_number');

        $sequence = 1;
        if (is_string($latest) && preg_match('/PO-(\d+)$/', $latest, $matches) === 1) {
            $sequence = (int) $matches[1] + 1;
        }

        return 'PO-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    private function resolveConversionFactor(
        int $productId,
        ?int $baseUnitId,
        ?int $secondaryUnitId,
        string $secondaryFactor,
        int $unitId,
    ): string {
        if ($baseUnitId === $unitId) {
            return '1.00000000';
        }

        if ($secondaryUnitId === $unitId) {
            return bcadd($secondaryFactor ?: '1', '0', 8);
        }

        $factor = ProductBarcode::query()
            ->forTenant($this->tenantContext->tenantId())
            ->where('product_id', $productId)
            ->where('unit_id', $unitId)
            ->where('is_active', true)
            ->orderByDesc('is_primary')
            ->value('conversion_factor');

        if ($factor === null) {
            throw ValidationException::withMessages([
                'unit_ulid' => 'The selected unit is not configured for this product.',
            ]);
        }

        return bcadd((string) $factor, '0', 8);
    }

    private function positiveQty(string $value, string $field): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/', $value) || bccomp($value, '0', 6) !== 1) {
            throw ValidationException::withMessages([$field => 'Quantity must be greater than zero.']);
        }

        return bcadd($value, '0', 6);
    }

    private function money(string $value, string $field): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages([$field => 'Amount must be a valid non-negative decimal.']);
        }

        return bcadd($value, '0', 4);
    }

    private function percent(string $value, string $field): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,8})?$/', $value) || bccomp($value, '100', 8) === 1) {
            throw ValidationException::withMessages([$field => 'Percentage must be between 0 and 100.']);
        }

        return bcadd($value, '0', 8);
    }
}
