<?php

namespace App\Reports;

use App\Actions\Purchases\BuildPurchaseEffectiveSnapshotAction;
use App\Actions\Purchases\BuildPurchaseReturnEffectiveSnapshotAction;
use App\Actions\Sales\BuildSaleEffectiveSnapshotAction;
use App\Actions\Sales\BuildSaleReturnEffectiveSnapshotAction;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseReturnStatus;
use App\Enums\SaleReturnStatus;
use App\Enums\SaleStatus;
use App\Exceptions\ApiException;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * Read-only effective posted document facts for operational reporting.
 * No journal/stock posting, no live-price valuation, no client-side financial authority.
 */
final class NextReportDocumentReader
{
    public function __construct(
        private readonly BuildSaleEffectiveSnapshotAction $sales,
        private readonly BuildPurchaseEffectiveSnapshotAction $purchases,
        private readonly BuildSaleReturnEffectiveSnapshotAction $saleReturns,
        private readonly BuildPurchaseReturnEffectiveSnapshotAction $purchaseReturns,
    ) {}

    /** @return Collection<int, array<string,mixed>> */
    public function read(TenantContext $context, string $from, string $to, array $types = []): Collection
    {
        $requested = $types === [] ? ['sale', 'purchase', 'sale_return', 'purchase_return'] : $types;
        $specs = [
            'sale' => [Sale::class, 'sale_date', SaleStatus::Posted->value, $this->sales, 'items', 'customer'],
            'purchase' => [PurchaseInvoice::class, 'invoice_date', PurchaseInvoiceStatus::Posted->value, $this->purchases, 'lines', 'supplier'],
            'sale_return' => [SaleReturn::class, 'return_date', SaleReturnStatus::Posted->value, $this->saleReturns, 'lines', 'customer'],
            'purchase_return' => [PurchaseReturn::class, 'return_date', PurchaseReturnStatus::Posted->value, $this->purchaseReturns, 'lines', 'supplier'],
        ];
        $documents = collect();
        foreach ($requested as $kind) {
            if (! isset($specs[$kind])) continue;
            [$model, $dateColumn, $posted, $reader, $linesKey, $partyKey] = $specs[$kind];
            $query = $model::query()->forTenant($context->tenantId())
                ->where('branch_id', $context->branchId())
                ->where('warehouse_id', $context->warehouseId())
                ->where('status', $posted)
                ->whereBetween($dateColumn, [$from, $to])
                ->orderBy('id');
            // Never silently truncate monetary reports.
            if ((clone $query)->limit(2001)->count() > 2000) {
                throw new ApiException('REPORT_TOO_LARGE', 'Select a shorter date range (over 2000 posted documents).', 422);
            }
            foreach ($query->with('latestRevision')->get() as $document) {
                $effective = $reader->current($document);
                $party = $effective[$partyKey] ?? null;
                $lines = [];
                foreach ($effective[$linesKey] ?? [] as $line) {
                    $product = $line['product'] ?? null;
                    $lines[] = [
                        'ulid' => $line['ulid'] ?? null,
                        'product' => is_array($product) ? [
                            'ulid' => $product['ulid'] ?? null,
                            'name' => $product['name'] ?? '',
                            'code' => $product['product_number'] ?? '',
                        ] : null,
                        'quantity' => (string) ($line['quantity'] ?? '0'),
                        'base_quantity' => (string) ($line['stock_quantity'] ?? $line['base_quantity'] ?? '0'),
                        'rate' => (string) ($line['unit_price'] ?? $line['unit_cost'] ?? '0'),
                        'amount' => (string) ($line['line_total'] ?? '0'),
                        'tax' => (string) ($line['tax_amount'] ?? '0'),
                        'further_tax' => (string) ($line['further_tax_amount'] ?? '0'),
                        'discount' => (string) ($line['discount_amount'] ?? '0'),
                        'tax_rate' => (string) ($line['tax_percent'] ?? $line['tax_pct'] ?? '0'),
                        'batch_number' => $line['batch_number'] ?? null,
                        'hs_code' => $line['hs_code'] ?? null,
                    ];
                }
                $documents->push([
                    'ulid' => $document->ulid,
                    'type' => $kind,
                    'date' => $document->{$dateColumn}->toDateString(),
                    'number' => $document->document_number,
                    'party' => is_array($party) ? [
                        'ulid' => $party['ulid'] ?? null,
                        'name' => $party['name'] ?? '',
                    ] : null,
                    'party_name' => $party['name'] ?? $effective['customer_name'] ?? 'Walk-in',
                    'amount' => (string) ($effective['grand_total'] ?? $document->grand_total),
                    'subtotal' => (string) ($effective['subtotal'] ?? '0'),
                    'discount' => (string) ($effective['discount_amount'] ?? '0'),
                    'tax' => (string) ($effective['tax_amount'] ?? '0'),
                    'further_tax' => (string) ($effective['further_tax_amount'] ?? '0'),
                    'lines' => $lines,
                ]);
            }
        }

        return $documents->sortBy([['date', 'asc'], ['type', 'asc'], ['number', 'asc']])->values();
    }
}
