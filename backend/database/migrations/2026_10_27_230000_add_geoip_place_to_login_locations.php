<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('login_location_observations', function (Blueprint $table): void {
            $table->string('country', 90)->nullable();
            $table->string('region', 90)->nullable();
            $table->string('city', 90)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('login_location_observations', function (Blueprint $table): void {
            $table->dropColumn(['country', 'region', 'city']);
        });
    }
};
