<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('login_location_observations', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('membership_id')->constrained('memberships')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            // Approximate location only (~1 km precision), never exact browser GPS.
            $table->decimal('latitude_approx', 5, 2);
            $table->decimal('longitude_approx', 5, 2);
            $table->unsignedInteger('accuracy_meters');
            $table->timestamp('observed_at');
            $table->timestamp('expires_at');
            $table->index(['tenant_id', 'membership_id', 'observed_at'], 'login_location_tenant_member_idx');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_location_observations');
    }
};
