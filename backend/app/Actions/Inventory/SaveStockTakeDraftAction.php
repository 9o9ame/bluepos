<?php

namespace App\Actions\Inventory;

use App\Enums\StockTakeStatus;
use App\Exceptions\ApiException;
use App\Models\InventoryStockTake;
use App\Models\InventoryStockTakeLine;
use Illuminate\Support\Facades\DB;

class SaveStockTakeDraftAction
{
    public function __construct(
        private readonly UpsertStockTakeLineAction $upsertLine,
    ) {}

    /**
     * @param array{
     *   count_date: string,
     *   notes?: string|null,
     *   lines: array<int, array{
     *     product_ulid: string,
     *     counted_quantity: string,
     *     notes?: string|null
     *   }>
     * } $data
     */
    public function execute(
        InventoryStockTake $document,
        array $data,
    ): InventoryStockTake {
        return DB::transaction(function () use ($document, $data): InventoryStockTake {
            $locked = InventoryStockTake::query()
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== StockTakeStatus::Draft) {
                throw new ApiException('DOCUMENT_POSTED', 'Posted stock takes cannot be edited.', 422);
            }

            $locked->count_date = $data['count_date'];
            $locked->notes = $data['notes'] ?? null;
            $locked->save();

            $keptLineIds = [];

            foreach ($data['lines'] as $lineData) {
                $line = $this->upsertLine->execute($locked, $lineData);
                $keptLineIds[] = $line->id;
            }

            $deleteQuery = InventoryStockTakeLine::query()
                ->where('stock_take_id', $locked->id);

            if ($keptLineIds !== []) {
                $deleteQuery->whereNotIn('id', $keptLineIds);
            }

            $deleteQuery->delete();

            return $locked->fresh(['warehouse', 'lines.product']) ?? $locked;
        });
    }
}
