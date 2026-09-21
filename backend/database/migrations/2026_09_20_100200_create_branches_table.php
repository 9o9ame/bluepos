<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('status', 32);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'name']);
        });

        DB::statement('CREATE UNIQUE INDEX branches_one_default_per_tenant ON branches (tenant_id) WHERE is_default = true');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS branches_one_default_per_tenant');
        Schema::dropIfExists('branches');
    }
};
