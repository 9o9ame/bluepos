<?php

namespace App\Actions\Sales;

use App\Enums\SaleReturnStatus;
use App\Enums\SaleStatus;
use App\Exceptions\ApiException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\PartyProfile;
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

            $sale = null;
            $customerId = null;
            $settlementAccountId = null;
            $salesmanId = null;
            $saleUlid = trim((string) ($data['sale_ulid'] ?? ''));

            if ($saleUlid !== '') {
                $sale = Sale::query()
                    ->forTenant($tenantId)
                    ->where('branch_id', $this->tenantContext->branchId())
                    ->where('warehouse_id', $this->tenantContext->warehouseId())
                    ->where('ulid', $saleUlid)
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

                $customerId = $sale->customer_id;
                $salesmanId = $sale->salesman_party_profile_id;
            } else {
                [$customerId, $settlementAccountId] = $this->resolveGeneralFrom($data, $tenantId);
                $salesmanId = $this->resolveSalesmanId($data, $tenantId);
            }

            $document = SaleReturn::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $this->tenantContext->branchId(),
                'warehouse_id' => $this->tenantContext->warehouseId(),
                'sale_id' => $sale?->id,
                'customer_id' => $customerId,
                'settlement_account_id' => $settlementAccountId,
                'salesman_party_profile_id' => $salesmanId,
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
                'sale_ulid' => $sale?->ulid,
                'return_mode' => $sale ? 'invoice' : 'general',
            ]);

            return $document->fresh(static::with()) ?? $document;
        });
    }

    /**
     * @param array<string,mixed> $data
     * @return array{0:int|null,1:int|null}
     */
    private function resolveGeneralFrom(array $data, int $tenantId): array
    {
        $customerUlid = trim((string) ($data['customer_ulid'] ?? ''));
        $accountUlid = trim((string) ($data['account_ulid'] ?? ''));

        if (($customerUlid === '') === ($accountUlid === '')) {
            throw ValidationException::withMessages([
                'from' => 'Select either a customer or an account for a general sales return.',
            ]);
        }

        if ($customerUlid !== '') {
            $customer = Customer::query()
                ->forTenant($tenantId)
                ->where('ulid', $customerUlid)
                ->where('is_active', true)
                ->first();

            if (! $customer) {
                throw ValidationException::withMessages([
                    'customer_ulid' => 'The selected customer is unavailable.',
                ]);
            }

            return [$customer->id, null];
        }

        $account = Account::query()
            ->forTenant($tenantId)
            ->where('ulid', $accountUlid)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            throw ValidationException::withMessages([
                'account_ulid' => 'The selected account is unavailable.',
            ]);
        }

        return [null, $account->id];
    }

    /** @param array<string,mixed> $data */
    private function resolveSalesmanId(array $data, int $tenantId): ?int
    {
        $salesmanUlid = trim((string) ($data['salesman_ulid'] ?? ''));
        if ($salesmanUlid === '') {
            return null;
        }

        $salesman = PartyProfile::query()
            ->forTenant($tenantId)
            ->where('ulid', $salesmanUlid)
            ->where('is_active', true)
            ->whereHas('types', fn ($query) => $query->where('type', 'salesman'))
            ->first();

        if (! $salesman) {
            throw ValidationException::withMessages([
                'salesman_ulid' => 'The selected salesman is unavailable.',
            ]);
        }

        return $salesman->id;
    }

    /** @return list<string> */
    public static function with(): array
    {
        return [
            'sale',
            'customer',
            'settlementAccount',
            'salesmanParty',
            'branch',
            'warehouse',
            'lines.product.category',
            'lines.unit',
            'lines.saleItem',
            'refunds.account',
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
