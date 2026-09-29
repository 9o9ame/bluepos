<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('party_type', 16);
            $table->unsignedBigInteger('party_id');
            $table->string('bank_name', 180);
            $table->string('branch_name', 180)->nullable();
            $table->string('branch_code', 64)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('account_number', 64)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'party_type', 'party_id']);
            $table->unique(
                ['tenant_id', 'party_type', 'party_id', 'account_number'],
                'party_bank_accounts_party_acct_unique',
            );
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('license_number', 120)->nullable()->after('account_type_id');
            $table->date('license_issued_on')->nullable()->after('license_number');
            $table->string('license_type', 8)->nullable()->after('license_issued_on');
            $table->date('license_expires_on')->nullable()->after('license_type');
            $table->boolean('ignore_warranty')->default(false)->after('license_expires_on');
            $table->boolean('print_license')->default(false)->after('ignore_warranty');
            $table->string('rf_id', 120)->nullable()->after('print_license');
            $table->string('store_allowed', 16)->nullable()->after('rf_id');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('license_number', 120)->nullable()->after('account_type_id');
            $table->date('license_issued_on')->nullable()->after('license_number');
            $table->string('license_type', 8)->nullable()->after('license_issued_on');
            $table->date('license_expires_on')->nullable()->after('license_type');
            $table->boolean('ignore_warranty')->default(false)->after('license_expires_on');
            $table->boolean('print_license')->default(false)->after('ignore_warranty');
            $table->string('rf_id', 120)->nullable()->after('print_license');
            $table->string('store_allowed', 16)->nullable()->after('rf_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'license_number',
                'license_issued_on',
                'license_type',
                'license_expires_on',
                'ignore_warranty',
                'print_license',
                'rf_id',
                'store_allowed',
            ]);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn([
                'license_number',
                'license_issued_on',
                'license_type',
                'license_expires_on',
                'ignore_warranty',
                'print_license',
                'rf_id',
                'store_allowed',
            ]);
        });

        Schema::dropIfExists('party_bank_accounts');
    }
};
