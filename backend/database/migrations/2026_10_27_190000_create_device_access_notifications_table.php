<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('device_access_notifications', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('recipient_membership_id')->constrained('memberships')->restrictOnDelete();
            $table->foreignId('access_request_id')->constrained('device_access_requests')->restrictOnDelete();
            $table->string('kind', 32);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['recipient_membership_id', 'access_request_id', 'kind'], 'device_access_notifications_unique');
            $table->index(['tenant_id', 'recipient_membership_id', 'read_at', 'created_at'], 'device_access_notifications_inbox_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_access_notifications');
    }
};
