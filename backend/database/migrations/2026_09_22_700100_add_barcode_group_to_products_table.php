<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('barcode_group_id')
                ->nullable()
                ->after('brand_id')
                ->constrained('barcode_groups')
                ->restrictOnDelete();
            $table->index(['tenant_id', 'barcode_group_id']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'barcode_group_id']);
            $table->dropConstrainedForeignId('barcode_group_id');
        });
    }
};
