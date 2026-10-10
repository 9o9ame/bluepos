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
            $table->string('normal_balance', 6)->default('debit')->after('is_payable');
        });

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
        Schema::table('account_types', function (Blueprint $table) {
            $table->dropColumn('normal_balance');
        });
    }
};
