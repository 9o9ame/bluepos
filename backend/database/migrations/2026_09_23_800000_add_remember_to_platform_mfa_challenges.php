<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_mfa_challenges', function (Blueprint $table) {
            $table->boolean('remember')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('platform_mfa_challenges', function (Blueprint $table) {
            $table->dropColumn('remember');
        });
    }
};
