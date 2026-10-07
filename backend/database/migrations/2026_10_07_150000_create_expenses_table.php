<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('payment_account_id')->constrained('accounts')->restrictOnDelete();
            $table->date('expense_date');
            $table->decimal('amount', 20, 4);
            $table->string('reference', 100)->nullable();
            $table->string('description', 500)->nullable();
            $table->char('journal_entry_ulid', 26)->nullable();
            $table->string('idempotency_key', 120);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'branch_id', 'expense_date']);
            $table->index('journal_entry_ulid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
