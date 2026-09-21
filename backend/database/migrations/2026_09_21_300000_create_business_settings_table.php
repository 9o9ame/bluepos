<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->restrictOnDelete();
            $table->string('business_name');
            $table->string('legal_name')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('city', 120)->nullable();
            $table->char('country_code', 2);
            $table->char('currency_code', 3);
            $table->string('timezone', 64);
            $table->string('date_format', 32);
            $table->string('number_format', 32);
            $table->string('tax_registration_number', 64)->nullable();
            $table->string('invoice_prefix', 16)->nullable();
            $table->string('receipt_footer')->nullable();
            $table->decimal('default_tax_percent', 12, 8);
            $table->boolean('negative_stock_allowed')->default(false);
            $table->boolean('expiry_tracking_enabled')->default(false);
            $table->boolean('batch_tracking_enabled')->default(false);
            $table->string('default_price_level', 32);
            $table->unsignedInteger('next_product_number')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_settings');
    }
};
