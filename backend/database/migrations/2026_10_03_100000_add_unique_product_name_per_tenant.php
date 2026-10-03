<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::select(
            'SELECT tenant_id, LOWER(name) AS name_key
             FROM products
             GROUP BY tenant_id, LOWER(name)
             HAVING COUNT(*) > 1
             LIMIT 1'
        );

        // Skip hard unique index when legacy duplicates already exist.
        // Application validation still blocks new same-name creates.
        if ($duplicates !== []) {
            return;
        }

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS products_tenant_name_lower_unique ON products (tenant_id, LOWER(name))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_tenant_name_lower_unique');
    }
};
