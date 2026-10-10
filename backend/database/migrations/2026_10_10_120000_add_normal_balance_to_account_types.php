<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_types', function (Blueprint $table) {
            $table->string('normal_balance', 6)->default('debit');
        });

        DB::statement(
            "ALTER TABLE account_types
             ADD CONSTRAINT account_types_normal_balance_check
             CHECK (normal_balance IN ('debit', 'credit'))"
        );

        DB::statement(<<<'SQL'
            UPDATE account_types AS account_type
            SET normal_balance = 'credit'
            FROM account_sub_heads AS sub_head
            JOIN account_main_heads AS main_head
              ON main_head.id = sub_head.main_head_id
            WHERE account_type.sub_head_id = sub_head.id
              AND UPPER(TRIM(main_head.name)) IN ('LIABILITIES', 'REVENUES', 'CAPITAL')
        SQL);
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE account_types DROP CONSTRAINT IF EXISTS account_types_normal_balance_check'
        );

        Schema::table('account_types', function (Blueprint $table) {
            $table->dropColumn('normal_balance');
        });
    }
};
