<?php

namespace App\Actions\Inventory;

use App\Enums\OpeningBalanceStatus;
use App\Exceptions\ApiException;
use App\Models\InventoryOpeningBalance;
use App\Security\AuditLogger;
use Illuminate\Support\Facades\DB;

class UpdateOpeningBalanceAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{document_date?: string, notes?: string|null}  $data
     */
    public function execute(InventoryOpeningBalance $document, array $data): InventoryOpeningBalance
    {
        if (! $document->isDraft()) {
            throw new ApiException('DOCUMENT_POSTED', 'Posted opening balances cannot be edited.', 422);
        }

        return DB::transaction(function () use ($document, $data): InventoryOpeningBalance {
            $document = InventoryOpeningBalance::query()
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->status !== OpeningBalanceStatus::Draft) {
                throw new ApiException('DOCUMENT_POSTED', 'Posted opening balances cannot be edited.', 422);
            }

            if (array_key_exists('document_date', $data)) {
                $document->document_date = $data['document_date'];
            }
            if (array_key_exists('notes', $data)) {
                $document->notes = $data['notes'];
            }
            $document->save();

            $this->audit->record('OPENING_BALANCE_UPDATED', [
                'resource_type' => 'inventory_opening_balance',
                'resource_ulid' => $document->ulid,
            ]);

            return $document->fresh(['warehouse', 'lines.product']) ?? $document;
        });
    }
}
