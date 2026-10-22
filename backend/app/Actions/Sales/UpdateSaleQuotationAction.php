<?php

namespace App\Actions\Sales;

use App\Actions\Documents\RecordDocumentRevisionAction;
use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\SaleQuotation;
use App\Models\SaleQuotationItem;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class UpdateSaleQuotationAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CreateSaleAction $salePreparation,
        private readonly RecordDocumentRevisionAction $revisions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array<string,mixed> $data
     */
    public function execute(
        SaleQuotation $quotation,
        array $data,
        string $reason,
    ): SaleQuotation {
        return DB::transaction(function () use ($quotation, $data, $reason): SaleQuotation {
            $quotation = SaleQuotation::query()
                ->forTenant($this->tenantContext->tenantId())
                ->where('branch_id', $this->tenantContext->branchId())
                ->where('warehouse_id', $this->tenantContext->warehouseId())
                ->whereKey($quotation->id)
                ->lockForUpdate()
                ->first();

            if (! $quotation) {
                throw new ApiException('NOT_FOUND', 'The requested quotation was not found.', 404);
            }

            $before = $quotation->load(CreateSaleQuotationAction::with())->toArray();

            $saleData = $data;
            $saleData['sale_date'] = $data['quotation_date'] ?? now()->toDateString();

            $prepared = $this->salePreparation->prepareDocument($saleData);
            $lines = $prepared['lines'];

            $subtotal = '0.0000';
            $discountTotal = '0.0000';
            $taxTotal = '0.0000';

            foreach ($lines as $line) {
                $subtotal = bcadd($subtotal, (string) $line['gross_amount'], 4);
                $discountTotal = bcadd($discountTotal, (string) $line['discount_amount'], 4);
                $taxTotal = bcadd($taxTotal, (string) $line['tax_amount'], 4);
            }

            $grandTotal = bcadd(
                bcsub($subtotal, $discountTotal, 4),
                $taxTotal,
                4,
            );

            $quotation->forceFill([
                'customer_id' => $prepared['customer']?->id,
                'salesman_party_profile_id' => $prepared['salesman']?->id,
                'quotation_date' => $data['quotation_date'] ?? now()->toDateString(),
                'price_type' => $prepared['price_type']->value,
                'subtotal' => $subtotal,
                'discount_amount' => $discountTotal,
                'tax_amount' => $taxTotal,
                'grand_total' => $grandTotal,
                'notes' => $data['notes'] ?? null,
            ])->save();

            SaleQuotationItem::query()
                ->where('sale_quotation_id', $quotation->id)
                ->delete();

            foreach ($lines as $index => $line) {
                /** @var Product $product */
                $product = $line['product'];

                SaleQuotationItem::query()->create([
                    'tenant_id' => $quotation->tenant_id,
                    'sale_quotation_id' => $quotation->id,
                    'product_id' => $product->id,
                    'unit_id' => $line['unit']->id,
                    'sale_scheme_id' => $line['sale_scheme_id'] ?? null,
                    'barcode' => $line['barcode'] ?? null,
                    'conversion_factor' => $line['conversion_factor'],
                    'line_kind' => $line['line_kind'],
                    'quantity' => $line['quantity'],
                    'stock_quantity' => $line['stock_quantity'],
                    'price_type' => $line['price_type'],
                    'unit_price' => $line['unit_price'],
                    'unit_price_override' => $line['unit_price_override'] ?? null,
                    'gross_amount' => $line['gross_amount'],
                    'discount_percent' => $line['discount_percent'],
                    'discount_amount' => $line['discount_amount'],
                    'tax_percent' => $line['tax_percent'],
                    'tax_amount' => $line['tax_amount'],
                    'line_total' => $line['line_total'],
                    'notes' => $line['notes'] ?? null,
                    'sort_order' => $index,
                ]);
            }

            $updated = $quotation->fresh(CreateSaleQuotationAction::with()) ?? $quotation;

            $this->revisions->execute(
                'sale_quotation',
                $quotation->ulid,
                $reason,
                $before,
                $updated->toArray(),
                (int) $quotation->branch_id,
                (int) $quotation->warehouse_id,
            );

            $this->audit->record('SALE_QUOTATION_UPDATED', [
                'resource_type' => 'sale_quotation',
                'resource_ulid' => $quotation->ulid,
                'line_count' => count($lines),
            ]);

            return $updated;
        });
    }
}
