<?php

namespace App\Actions\Sales;

use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\SaleQuotation;
use App\Models\SaleQuotationItem;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class CreateSaleQuotationAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CreateSaleAction $salePreparation,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function execute(array $data, string $idempotencyKey): SaleQuotation
    {
        $tenantId = $this->tenantContext->tenantId();
        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
            throw new ApiException(
                'IDEMPOTENCY_KEY_REQUIRED',
                'A valid Idempotency-Key header is required to save a quotation.',
                422,
            );
        }

        return DB::transaction(function () use ($data, $idempotencyKey, $tenantId): SaleQuotation {
            $existing = SaleQuotation::query()
                ->forTenant($tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $this->load($existing);
            }

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

            $quotation = SaleQuotation::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $prepared['warehouse']->branch_id,
                'warehouse_id' => $prepared['warehouse']->id,
                'customer_id' => $prepared['customer']?->id,
                'salesman_party_profile_id' => $prepared['salesman']?->id,
                'document_number' => $this->nextDocumentNumber($tenantId),
                'quotation_date' => $data['quotation_date'] ?? now()->toDateString(),
                'price_type' => $prepared['price_type']->value,
                'subtotal' => $subtotal,
                'discount_amount' => $discountTotal,
                'tax_amount' => $taxTotal,
                'grand_total' => $grandTotal,
                'idempotency_key' => $idempotencyKey,
                'notes' => $data['notes'] ?? null,
                'created_by' => $this->tenantContext->userId(),
            ]);

            foreach ($lines as $index => $line) {
                /** @var Product $product */
                $product = $line['product'];

                SaleQuotationItem::query()->create([
                    'tenant_id' => $tenantId,
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

            $this->audit->record('SALE_QUOTATION_CREATED', [
                'resource_type' => 'sale_quotation',
                'resource_ulid' => $quotation->ulid,
                'line_count' => count($lines),
            ]);

            return $this->load($quotation);
        });
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
            'items' => fn ($query) => $query->orderBy('sort_order'),
            'items.product',
            'items.unit',
            'items.saleScheme',
        ];
    }

    private function nextDocumentNumber(int $tenantId): string
    {
        $latest = SaleQuotation::query()
            ->forTenant($tenantId)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('document_number');

        $seq = 1;

        if (
            is_string($latest) &&
            preg_match('/QUO-(\d+)$/', $latest, $matches) === 1
        ) {
            $seq = (int) $matches[1] + 1;
        }

        return 'QUO-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }

    private function load(SaleQuotation $quotation): SaleQuotation
    {
        return $quotation->fresh(static::with()) ?? $quotation;
    }
}
