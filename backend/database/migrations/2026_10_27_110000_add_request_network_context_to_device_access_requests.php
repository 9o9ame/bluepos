<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('device_access_requests', function (Blueprint $table): void {
            $table->ipAddress('request_ip')->nullable();
            $table->string('request_user_agent', 180)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('device_access_requests', function (Blueprint $table): void {
            $table->dropColumn(['request_ip', 'request_user_agent']);
        });
    }
};
