<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('platform_tenant_imports', function (Blueprint $table) {
            $table->string('source_path', 255)->nullable();
            $table->unsignedInteger('total_rows')->nullable();
        });
    }
    public function down(): void {
        Schema::table('platform_tenant_imports', function (Blueprint $table) {
            $table->dropColumn(['source_path', 'total_rows']);
        });
    }
};
