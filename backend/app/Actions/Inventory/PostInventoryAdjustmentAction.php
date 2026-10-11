<?php

namespace App\Actions\Inventory;

use App\Accounting\OpeningBalancePoster;
use App\Enums\InventoryAdjustmentStatus;
use App\Enums\JournalStatus;
use App\Enums\ProductStatus;
use App\Enums\StockMovementType;
use App\Enums\WarehouseStatus;
use App\Exceptions\ApiException;
use App\Models\Account;
use App\Models\BusinessSetting;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAdjustmentLine;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\StockBalance;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostInventoryAdjustmentAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PostStockMovementAction $postMovement,
        private readonly OpeningBalancePoster $poster,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(InventoryAdjustment $document): InventoryAdjustment
    {
        return DB::transaction(function () use ($document): InventoryAdjustment {
            $tenantId = $this->tenantContext->tenantId();

            $document = InventoryAdjustment::query()
                ->forTenant($tenantId)
                ->whereKey($document->id)
                ->with(['warehouse', 'account', 'lines.product'])
                ->lockForUpdate()
                ->firstOrFail();

            if (
                (int) $document->branch_id !== $this->tenantContext->branchId()
                || (int) $document->warehouse_id !== $this->tenantContext->warehouseId()
            ) {
                throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
            }

            if ($document->status === InventoryAdjustmentStatus::Posted) {
                return $document->fresh(['warehouse', 'account', 'lines.product']) ?? $document;
            }

            if ($document->lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => 'Add at least one adjustment line before posting.',
                ]);
            }

            $warehouse = $document->warehouse;
            if (! $warehouse || $warehouse->status !== WarehouseStatus::Active) {
                throw ValidationException::withMessages([
                    'warehouse' => 'Inventory adjustment cannot be posted to an inactive warehouse.',
                ]);
            }

            $offsetAccount = Account::query()
                ->forTenant($tenantId)
                ->whereKey($document->account_id)
                ->where('is_active', true)
                ->first();

            if (! $offsetAccount) {
                throw new ApiException(
                    'ADJUSTMENT_ACCOUNT_REQUIRED',
                    'Select an active adjustment account before posting.',
                    422,
                );
            }

            $settings = BusinessSetting::query()->forTenant($tenantId)->first();
            $inventoryAccount = $settings?->inventory_account_id
                ? Account::query()
                    ->forTenant($tenantId)
                    ->whereKey((int) $settings->inventory_account_id)
                    ->where('is_active', true)
                    ->first()
                : null;

            if (! $inventoryAccount) {
                throw new ApiException(
                    'INVENTORY_ACCOUNT_REQUIRED',
                    'Configure an active Inventory Control account before posting inventory adjustments.',
                    422,
                );
            }

            if ((int) $inventoryAccount->id === (int) $offsetAccount->id) {
                throw new ApiException(
                    'INVENTORY_ADJUSTMENT_CONFIGURATION_INVALID',
                    'Inventory Control and the selected adjustment account must be different.',
                    422,
                );
            }

            $existingJournal = JournalEntry::query()
                ->forTenant($tenantId)
                ->where('document_type', JournalEntry::DOCUMENT_INVENTORY_ADJUSTMENT)
                ->where('document_id', $document->id)
                ->lockForUpdate()
                ->first();

            if ($existingJournal && ! $existingJournal->isPosted()) {
                throw new ApiException(
                    'INVENTORY_ADJUSTMENT_ACCOUNTING_CONFLICT',
                    'An unfinished inventory adjustment journal already exists.',
                    409,
                );
            }

            if ($existingJournal && $existingJournal->isPosted()) {
                throw new ApiException(
                    'INVENTORY_ADJUSTMENT_ACCOUNTING_CONFLICT',
                    'A posted inventory adjustment journal already exists for this draft.',
                    409,
                );
            }

            $accountingRows = [];

            foreach ($document->lines as $line) {
                /** @var InventoryAdjustmentLine $line */
                $product = $line->product;

                if (! $product || $product->status !== ProductStatus::Active || ! $product->is_active) {
                    throw ValidationException::withMessages([
                        'product_ulid' => 'Inventory adjustment cannot be posted for an inactive product.',
                    ]);
                }

                $delta = bcadd((string) $line->quantity_delta, '0', 6);
                if (bccomp($delta, '0', 6) === 0) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Adjustment quantity must not be zero.',
                    ]);
                }

                StockBalance::query()->insertOrIgnore([
                    'tenant_id' => $tenantId,
                    'branch_id' => $warehouse->branch_id,
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => '0.000000',
                    'average_cost' => null,
                    'stock_value' => null,
                    'last_movement_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $balance = StockBalance::query()
                    ->where('tenant_id', $tenantId)
                    ->where('warehouse_id', $warehouse->id)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $system = bcadd((string) $balance->quantity, '0', 6);
                $after = bcadd($system, $delta, 6);

                if (
                    bccomp($after, '0', 6) < 0
                    && ! (bool) ($settings?->negative_stock_allowed ?? false)
                ) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Adjustment quantity cannot reduce stock below zero.',
                    ]);
                }

                $unitCost = bcadd((string) ($balance->average_cost ?? '0'), '0', 4);
                $absoluteQty = bccomp($delta, '0', 6) < 0
                    ? bcsub('0.000000', $delta, 6)
                    : $delta;
                $amount = bcmul($absoluteQty, $unitCost, 4);

                $line->system_quantity = $system;
                $line->after_quantity = $after;
                $line->unit_cost = $unitCost;
                $line->total_amount = $amount;
                $line->save();

                $movementType = bccomp($delta, '0', 6) > 0
                    ? StockMovementType::AdjustmentIn
                    : StockMovementType::AdjustmentOut;

                $this->postMovement->execute([
                    'warehouse' => $warehouse,
                    'product' => $product,
                    'movement_type' => $movementType,
                    'quantity' => $delta,
                    'unit_cost' => $unitCost,
                    'reference_type' => 'inventory_adjustment',
                    'reference_ulid' => $document->ulid,
                    'reference_line_ulid' => $line->ulid,
                    'occurred_at' => $document->adjustment_date?->copy()->startOfDay() ?? now(),
                    'idempotency_key' => 'inventory-adjustment:'.$document->ulid.':line:'.$line->ulid,
                    'notes' => $line->notes,
                    'update_average_cost' => false,
                ]);

                if (bccomp($amount, '0', 4) > 0) {
                    $accountingRows[] = [
                        'product_name' => $product->name,
                        'inbound' => bccomp($delta, '0', 6) > 0,
                        'amount' => $amount,
                    ];
                }
            }

            if ($accountingRows !== []) {
                $journal = JournalEntry::query()->create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $document->branch_id,
                    'document_type' => JournalEntry::DOCUMENT_INVENTORY_ADJUSTMENT,
                    'document_id' => $document->id,
                    'idempotency_key' => 'inventory-adjustment-accounting:'.$document->ulid,
                    'entry_date' => $document->adjustment_date?->toDateString() ?? now()->toDateString(),
                    'description' => 'Inventory adjustment '.$document->document_number,
                    'status' => JournalStatus::Posted,
                    'created_by' => $this->tenantContext->userId(),
                    'posted_by' => $this->tenantContext->userId(),
                    'posted_at' => now(),
                ]);

                $sort = 0;
                foreach ($accountingRows as $row) {
                    $amount = $row['amount'];
                    $inbound = $row['inbound'];
                    $description = 'Inventory adjustment - '.$row['product_name'];

                    JournalLine::query()->create([
                        'tenant_id' => $tenantId,
                        'journal_entry_id' => $journal->id,
                        'account_id' => $inventoryAccount->id,
                        'description' => $description,
                        'debit' => $inbound ? $amount : '0.0000',
                        'credit' => $inbound ? '0.0000' : $amount,
                        'sort_order' => $sort++,
                    ]);

                    JournalLine::query()->create([
                        'tenant_id' => $tenantId,
                        'journal_entry_id' => $journal->id,
                        'account_id' => $offsetAccount->id,
                        'description' => $description,
                        'debit' => $inbound ? '0.0000' : $amount,
                        'credit' => $inbound ? $amount : '0.0000',
                        'sort_order' => $sort++,
                    ]);
                }

                $journal->load('lines');
                $this->poster->assertBalanced($journal);
            }

            $document->status = InventoryAdjustmentStatus::Posted;
            $document->posted_by = $this->tenantContext->userId();
            $document->posted_at = now();
            $document->save();

            $this->audit->record('INVENTORY_ADJUSTMENT_POSTED', [
                'resource_type' => 'inventory_adjustment',
                'resource_ulid' => $document->ulid,
                'line_count' => $document->lines->count(),
            ]);

            return $document->fresh(['warehouse', 'account', 'lines.product']) ?? $document;
        });
    }
}
