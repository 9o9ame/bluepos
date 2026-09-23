<?php

namespace App\Actions\Inventory;

use App\Enums\OpeningBalanceStatus;
use App\Exceptions\ApiException;
use App\Models\InventoryOpeningBalance;
use App\Models\InventoryOpeningBalanceLine;
use App\Security\AuditLogger;
use Illuminate\Support\Facades\DB;

class DeleteOpeningBalanceLineAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(InventoryOpeningBalance $document, InventoryOpeningBalanceLine $line): void
    {
        if (! $document->isDraft()) {
            throw new ApiException('DOCUMENT_POSTED', 'Posted opening balances cannot be edited.', 422);
        }

        DB::transaction(function () use ($document, $line): void {
            $document = InventoryOpeningBalance::query()
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->status !== OpeningBalanceStatus::Draft) {
                throw new ApiException('DOCUMENT_POSTED', 'Posted opening balances cannot be edited.', 422);
            }

            $line = InventoryOpeningBalanceLine::query()
                ->whereKey($line->id)
                ->where('opening_balance_id', $document->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lineUlid = $line->ulid;
            $line->delete();

            $this->audit->record('OPENING_BALANCE_UPDATED', [
                'resource_type' => 'inventory_opening_balance',
                'resource_ulid' => $document->ulid,
                'deleted_line_ulid' => $lineUlid,
            ]);
        });
    }
}
