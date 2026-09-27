<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('appearance_theme', 16)->default('system')->after('security_version');
            $table->string('appearance_skin', 16)->default('classic')->after('appearance_theme');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'appearance_theme',
                'appearance_skin',
            ]);
        });
    }
};
