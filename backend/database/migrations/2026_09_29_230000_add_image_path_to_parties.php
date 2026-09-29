<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('image_path', 500)->nullable()->after('store_allowed');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('image_path', 500)->nullable()->after('store_allowed');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('image_path', 500)->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
