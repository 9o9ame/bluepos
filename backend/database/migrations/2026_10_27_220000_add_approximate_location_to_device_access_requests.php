<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('device_access_requests', function (Blueprint $table): void {
            $table->decimal('request_latitude_approx', 5, 2)->nullable();
            $table->decimal('request_longitude_approx', 5, 2)->nullable();
            $table->timestamp('request_location_expires_at')->nullable();
            $table->index('request_location_expires_at', 'device_access_request_location_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::table('device_access_requests', function (Blueprint $table): void {
            $table->dropIndex('device_access_request_location_expiry_idx');
            $table->dropColumn(['request_latitude_approx', 'request_longitude_approx', 'request_location_expires_at']);
        });
    }
};
