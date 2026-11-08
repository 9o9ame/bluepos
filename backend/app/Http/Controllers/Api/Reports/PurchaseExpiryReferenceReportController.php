<?php

namespace App\Http\Controllers\Api\Reports;

use App\Actions\Purchases\BuildPurchaseEffectiveSnapshotAction;
use App\Authz\PermissionService;
use App\Enums\PurchaseInvoiceStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\PurchaseInvoice;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Purchase-expiry REFERENCE register, not remaining batch inventory.
 *
 * BluePOS currently stores batch/expiry on purchase lines but does not
 * allocate subsequent sales/returns/transfers to lots. Purchase quantities
 * must NEVER be relabeled as remaining stock or available-to-sell stock.
 */
final class PurchaseExpiryReferenceReportController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly BuildPurchaseEffectiveSnapshotAction $snapshots,
    ) {}

    public function __invoke(Request $request, TenantContext $context): array
    {
        if (! $this->permissions->can('reports.inventory')) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to view inventory reports.', 403);
        }

        $input = $request->validate([
            'report_date' => ['required', 'date_format:Y-m-d'],
            'horizon_days' => ['required', 'integer', 'min:1', 'max:7300'],
            'include_expired' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'string', 'max:180'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $reportDate = CarbonImmutable::createFromFormat('Y-m-d', $input['report_date'])->startOfDay();
        $lastDate = $reportDate->addDays((int) $input['horizon_days'])->toDateString();
        $includeExpired = (bool) ($input['include_expired'] ?? false);
        $needle = mb_strtolower(trim((string) ($input['q'] ?? '')));

        $documents = PurchaseInvoice::query()
            ->forTenant($context->tenantId())
            ->where('branch_id', $context->branchId())
            ->where('warehouse_id', $context->warehouseId())
            ->where('status', PurchaseInvoiceStatus::Posted->value)
            ->with(['latestRevision', 'supplier', 'lines.product', 'lines.unit'])
            ->orderBy('id')
            ->limit(501)->get();
        if ($documents->count() > 500) {
            throw new ApiException(
                'REPORT_TOO_LARGE',
                'Purchase expiry reference has over 500 posted documents. A narrower source filter is required.',
                422,
            );
        }

        $rows = [];
        foreach ($documents as $document) {
            $snapshot = $this->snapshots->current($document);
            foreach ($snapshot['lines'] ?? [] as $line) {
                if (! is_array($line) || empty($line['expiry_date'])) {
                    continue;
                }

                $expiry = CarbonImmutable::createFromFormat('Y-m-d', (string) $line['expiry_date'])->startOfDay();
                $expiryDate = $expiry->toDateString();
                if ($expiryDate > $lastDate || (! $includeExpired && $expiryDate < $input['report_date'])) {
                    continue;
                }

                $product = $line['product'] ?? null;
                if (! is_array($product) || empty($product['ulid'])) {
                    continue;
                }
                $batch = (string) ($line['batch_number'] ?? '');
                $name = (string) ($product['name'] ?? '');
                $number = (string) ($product['product_number'] ?? '');
                if ($needle !== '' && ! str_contains(mb_strtolower($name.' '.$number.' '.$batch), $needle)) {
                    continue;
                }

                $rows[] = [
                    'purchase_ulid' => $document->ulid,
                    'purchase_number' => $document->document_number,
                    'purchase_line_ulid' => $line['ulid'] ?? null,
                    'purchase_date' => $document->invoice_date?->toDateString(),
                    'product' => [
                        'ulid' => $product['ulid'],
                        'product_number' => $product['product_number'] ?? null,
                        'name' => $name,
                    ],
                    'batch_number' => $batch !== '' ? $batch : null,
                    'expiry_date' => $expiryDate,
                    'days_to_expiry' => (int) $reportDate->diffInDays($expiry, false),
                    'purchased_base_quantity' => bcadd((string) ($line['base_quantity'] ?? '0'), '0', 6),
                    'remaining_quantity' => null,
                    'remaining_quantity_unavailable_reason' => 'No lot-level stock movements or sale allocations exist.',
                ];
                if (count($rows) > 15000) {
                    throw new ApiException('REPORT_TOO_LARGE', 'Too many purchase expiry lines to preview.', 422);
                }
            }
        }

        usort($rows, static fn (array $a, array $b) =>
            [$a['expiry_date'], $a['purchase_number'], $a['purchase_line_ulid'] ?? '']
            <=> [$b['expiry_date'], $b['purchase_number'], $b['purchase_line_ulid'] ?? '']);
        $pageNumber = (int) ($input['page'] ?? 1);
        $perPage = (int) ($input['per_page'] ?? 100);
        $count = count($rows);

        return [
            'data' => array_slice($rows, ($pageNumber - 1) * $perPage, $perPage),
            'meta' => [
                'report_type' => 'purchase_expiry_reference_not_remaining_stock',
                'report_date' => $input['report_date'],
                'horizon_days' => (int) $input['horizon_days'],
                'include_expired' => $includeExpired,
                'warehouse' => ['ulid' => $context->warehouse()->ulid, 'name' => $context->warehouse()->name],
                'current_page' => $pageNumber,
                'last_page' => max(1, (int) ceil($count / $perPage)),
                'per_page' => $perPage,
                'total' => $count,
                'remaining_stock_available' => false,
                'limitation' => 'Purchased quantities are historical invoice quantities, NOT remaining batch stock. Current posted purchase snapshots may include amendments after purchase date.',
            ],
        ];
    }
}
