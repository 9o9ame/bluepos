<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('recovery_email')->nullable();
            $table->string('recovery_phone', 32)->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('password_changed_at')->nullable();
            $table->unsignedInteger('security_version')->default(1);
        });

        DB::statement('ALTER TABLE users ALTER COLUMN email DROP NOT NULL');
        DB::table('users')->whereNull('recovery_email')->update([
            'recovery_email' => DB::raw('email'),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'recovery_email',
                'recovery_phone',
                'must_change_password',
                'password_changed_at',
                'security_version',
            ]);
        });
    }
};
