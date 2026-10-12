<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_headings', function (Blueprint $table) {
            $table->jsonb('layout_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('report_headings', function (Blueprint $table) {
            $table->dropColumn('layout_settings');
        });
    }
};
