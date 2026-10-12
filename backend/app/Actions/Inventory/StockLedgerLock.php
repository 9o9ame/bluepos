<?php

namespace App\Actions\Inventory;

use Illuminate\Support\Facades\DB;

class StockLedgerLock
{
    public function acquire(int $tenantId): void
    {
        DB::select(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ['bluepos:stock:tenant:'.$tenantId],
        );
    }
}
