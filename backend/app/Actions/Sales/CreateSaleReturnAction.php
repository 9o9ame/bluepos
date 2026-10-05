<?php

namespace App\Actions\Sales;

use App\Enums\SaleReturnStatus;
use App\Enums\SaleStatus;
use App\Exceptions\ApiException;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateSaleReturnAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array<string,mixed> $data
     */
    public function execute(array $data, string $idempotencyKey): SaleReturn
    {
        $tenantId = $this->tenantContext->tenantId();
        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
            throw new ApiException(
                'IDEMPOTENCY_KEY_REQUIRED',
                'A valid Idempotency-Key header is required to create a sales return.',
                422,
            );
        }

        return DB::transaction(function () use ($data, $tenantId, $idempotencyKey): SaleReturn {
            $existing = SaleReturn::query()
                ->forTenant($tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing->fresh(static::with()) ?? $existing;
            }

            $sale = Sale::query()
                ->forTenant($tenantId)
                ->where('branch_id', $this->tenantContext->branchId())
                ->where('warehouse_id', $this->tenantContext->warehouseId())
                ->where('ulid', (string) $data['sale_ulid'])
                ->lockForUpdate()
                ->first();

            if (! $sale) {
                throw new ApiException('NOT_FOUND', 'The requested sale was not found.', 404);
            }

            if ($sale->status !== SaleStatus::Posted) {
                throw ValidationException::withMessages([
                    'sale_ulid' => 'Sales returns can only be created from posted sales.',
                ]);
            }

            $document = SaleReturn::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $this->tenantContext->branchId(),
                'warehouse_id' => $this->tenantContext->warehouseId(),
                'sale_id' => $sale->id,
                'customer_id' => $sale->customer_id,
                'salesman_party_profile_id' => $sale->salesman_party_profile_id,
                'document_number' => $this->nextDocumentNumber($tenantId),
                'return_date' => $data['return_date'] ?? now()->toDateString(),
                'status' => SaleReturnStatus::Draft,
                'subtotal' => '0.0000',
                'discount_amount' => '0.0000',
                'tax_amount' => '0.0000',
                'grand_total' => '0.0000',
                'refund_amount' => '0.0000',
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $this->tenantContext->userId(),
            ]);

            $this->audit->record('SALE_RETURN_CREATED', [
                'resource_type' => 'sale_return',
                'resource_ulid' => $document->ulid,
                'sale_ulid' => $sale->ulid,
            ]);

            return $document->fresh(static::with()) ?? $document;
        });
    }

    /** @return list<string> */
    public static function with(): array
    {
        return [
            'sale',
            'customer',
            'salesmanParty',
            'branch',
            'warehouse',
            'lines.product.category',
            'lines.unit',
            'lines.saleItem',
        ];
    }

    private function nextDocumentNumber(int $tenantId): string
    {
        $latest = SaleReturn::query()
            ->forTenant($tenantId)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('document_number');

        $seq = 1;
        if (is_string($latest) && preg_match('/SR-(\d+)$/', $latest, $matches) === 1) {
            $seq = (int) $matches[1] + 1;
        }

        return 'SR-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
