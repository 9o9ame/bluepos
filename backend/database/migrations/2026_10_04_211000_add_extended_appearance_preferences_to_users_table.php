<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('appearance_primary_theme', 16)->default('blue')->after('appearance_font');
            $table->string('appearance_density', 16)->default('comfortable')->after('appearance_primary_theme');
            $table->string('appearance_radius', 16)->default('medium')->after('appearance_density');
            $table->string('appearance_shadow', 16)->default('soft')->after('appearance_radius');
            $table->boolean('appearance_animations')->default(true)->after('appearance_shadow');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'appearance_primary_theme',
                'appearance_density',
                'appearance_radius',
                'appearance_shadow',
                'appearance_animations',
            ]);
        });
    }
};
