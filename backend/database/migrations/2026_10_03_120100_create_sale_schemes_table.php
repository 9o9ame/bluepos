<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_schemes', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name', 180);
            $table->string('scheme_type', 32)->default('spend_amount');
            $table->string('apply_mode', 32)->default('salesman');
            $table->decimal('min_sale_amount', 20, 4)->default(0);
            $table->foreignId('reward_product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('max_reward_qty', 20, 6)->default(1);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_stackable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'scheme_type']);
            $table->index(['tenant_id', 'apply_mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_schemes');
    }
};
