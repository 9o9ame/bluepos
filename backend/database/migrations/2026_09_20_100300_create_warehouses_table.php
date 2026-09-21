<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('status', 32);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'branch_id', 'code']);
            $table->index(['tenant_id', 'branch_id']);
        });

        DB::statement('CREATE UNIQUE INDEX warehouses_one_default_per_branch ON warehouses (tenant_id, branch_id) WHERE is_default = true');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS warehouses_one_default_per_branch');
        Schema::dropIfExists('warehouses');
    }
};
