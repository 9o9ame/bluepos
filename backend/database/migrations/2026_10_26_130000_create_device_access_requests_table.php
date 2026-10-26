<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('device_access_requests', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('membership_id')->constrained('memberships')->restrictOnDelete();
            $table->foreignId('device_id')->constrained('devices')->restrictOnDelete();
            $table->string('network_hash', 64)->nullable();
            $table->string('status', 16)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->unique(['membership_id', 'device_id', 'network_hash'], 'device_access_identity_network_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_access_requests');
    }
};
