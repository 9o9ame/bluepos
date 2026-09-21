<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('module', 64);
            $table->string('description')->nullable();
            $table->boolean('is_platform')->default(false);
            $table->timestamps();

            $table->index('module');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
