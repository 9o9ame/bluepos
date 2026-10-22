<?php

namespace App\Actions\Purchases;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Tenant;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class CreatePurchaseOrderAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PreparePurchaseOrderAction $prepare,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data): PurchaseOrder {
            $tenantId = $this->tenantContext->tenantId();
            $prepared = $this->prepare->execute($data);
            $warehouse = $prepared['warehouse'];
            $supplier = $prepared['supplier'];

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

            foreach ($prepared['lines'] as $line) {
                PurchaseOrderLine::query()->create([
                    'tenant_id' => $tenantId,
                    'purchase_order_id' => $order->id,
                    'product_id' => $line['product']->id,
                    'unit_id' => $line['unit']->id,
                    'quantity' => $line['quantity'],
                    'conversion_factor' => $line['conversion_factor'],
                    'base_quantity' => $line['base_quantity'],
                    'unit_price' => $line['unit_price'],
                    'gross_amount' => $line['gross_amount'],
                    'discount_percent' => $line['discount_percent'],
                    'discount_amount' => $line['discount_amount'],
                    'line_total' => $line['line_total'],
                    'notes' => $line['notes'],
                ]);
            }

            $order->forceFill([
                'subtotal' => $prepared['subtotal'],
                'discount_amount' => $prepared['discount_amount'],
                'grand_total' => $prepared['grand_total'],
            ])->save();

            $this->audit->record('PURCHASE_ORDER_CREATED', [
                'resource_type' => 'purchase_order',
                'resource_ulid' => $order->ulid,
            ]);

            return $order->fresh(['supplier', 'branch', 'warehouse', 'lines.product', 'lines.unit']) ?? $order;
        });
    }

    public function previewNextDocumentNumber(): string
    {
        return $this->calculateNextDocumentNumber(
            $this->tenantContext->tenantId(),
            false,
        );
    }

    private function nextDocumentNumber(int $tenantId): string
    {
        return $this->calculateNextDocumentNumber($tenantId, true);
    }

    private function calculateNextDocumentNumber(
        int $tenantId,
        bool $lock,
    ): string {
        $query = PurchaseOrder::query()
            ->forTenant($tenantId)
            ->orderByDesc('id');

        if ($lock) {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
        }

        $latest = $query->value('document_number');

        $sequence = 1;
        if (is_string($latest) && preg_match('/PO-(\d+)$/', $latest, $matches) === 1) {
            $sequence = (int) $matches[1] + 1;
        }

        return 'PO-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
