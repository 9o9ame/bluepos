<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_tenant_imports', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('platform_user_id')->constrained('platform_users')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source_name', 255);
            $table->string('format', 20);
            $table->char('fingerprint', 64);
            $table->string('status', 30)->default('processing');
            $table->timestamps();
            $table->unique(['tenant_id', 'fingerprint']);
        });
        Schema::create('platform_tenant_import_rows', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('import_id')->constrained('platform_tenant_imports')->restrictOnDelete();
            $table->unsignedInteger('source_row');
            $table->string('source_identity', 255);
            $table->string('outcome', 20);
            $table->jsonb('result');
            $table->foreignId('opening_balance_id')->nullable()->constrained('inventory_opening_balances')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['import_id', 'source_row']);
        });
        DB::statement('ALTER TABLE products ALTER COLUMN created_by DROP NOT NULL');
        DB::statement('ALTER TABLE inventory_opening_balances ALTER COLUMN created_by DROP NOT NULL');
    }

    public function down(): void
    {
        if (DB::table('platform_tenant_imports')->exists()) {
            throw new RuntimeException('Cannot discard import tracking while recorded imports exist.');
        }
        if (DB::table('products')->whereNull('created_by')->exists()
            || DB::table('inventory_opening_balances')->whereNull('created_by')->exists()) {
            throw new RuntimeException('Cannot reverse actor nullability while platform-created records exist.');
        }
        DB::statement('ALTER TABLE products ALTER COLUMN created_by SET NOT NULL');
        DB::statement('ALTER TABLE inventory_opening_balances ALTER COLUMN created_by SET NOT NULL');
        Schema::dropIfExists('platform_tenant_import_rows');
        Schema::dropIfExists('platform_tenant_imports');
    }
};
