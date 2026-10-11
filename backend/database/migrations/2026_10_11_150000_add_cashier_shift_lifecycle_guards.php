<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashier_shifts', function (Blueprint $table): void {
            $table->string('open_idempotency_key', 120)->nullable()->after('notes');
            $table->string('close_idempotency_key', 120)->nullable()->after('open_idempotency_key');
        });

        DB::statement(
            "CREATE UNIQUE INDEX cashier_shifts_one_open_per_member_branch
             ON cashier_shifts (tenant_id, branch_id, membership_id)
             WHERE status = 'open'"
        );

        DB::statement(
            'CREATE UNIQUE INDEX cashier_shifts_open_idempotency_unique
             ON cashier_shifts (tenant_id, open_idempotency_key)
             WHERE open_idempotency_key IS NOT NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX cashier_shifts_close_idempotency_unique
             ON cashier_shifts (tenant_id, close_idempotency_key)
             WHERE close_idempotency_key IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS cashier_shifts_close_idempotency_unique');
        DB::statement('DROP INDEX IF EXISTS cashier_shifts_open_idempotency_unique');
        DB::statement('DROP INDEX IF EXISTS cashier_shifts_one_open_per_member_branch');

        Schema::table('cashier_shifts', function (Blueprint $table): void {
            $table->dropColumn(['open_idempotency_key', 'close_idempotency_key']);
        });
    }
};
