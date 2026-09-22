<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_reset_challenges', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempts')->default(0)->after('token_hash');
        });

        Schema::create('platform_password_reset_challenges', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('platform_user_id')->constrained('platform_users')->restrictOnDelete();
            $table->string('token_hash');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['platform_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_password_reset_challenges');

        Schema::table('password_reset_challenges', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });
    }
};
