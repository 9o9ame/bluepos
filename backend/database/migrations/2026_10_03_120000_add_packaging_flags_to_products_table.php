<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_packaging')->default(false)->after('track_expiry');
            $table->decimal('max_free_qty_per_sale', 20, 6)->nullable()->after('is_packaging');
            $table->index(['tenant_id', 'is_packaging']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'is_packaging']);
            $table->dropColumn(['is_packaging', 'max_free_qty_per_sale']);
        });
    }
};
