<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_profiles', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name', 180);
            $table->string('deals_in', 180)->nullable();
            $table->string('contact_person', 180)->nullable();
            $table->string('mobile', 64)->nullable();
            $table->string('mobile_secondary', 64)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('phone_secondary', 64)->nullable();
            $table->string('email', 180)->nullable();
            $table->text('address')->nullable();
            $table->text('billing_address')->nullable();
            $table->string('area', 120)->nullable();
            $table->boolean('invoice_restricted')->default(false);
            $table->decimal('credit_limit_amount', 20, 4)->default(0);
            $table->unsignedInteger('credit_limit_days')->default(0);
            $table->decimal('add_percent', 12, 8)->default(0);
            $table->string('cnic', 32)->nullable();
            $table->string('ntn', 64)->nullable();
            $table->string('stn', 64)->nullable();
            $table->text('formulas')->nullable();
            $table->string('license_number', 120)->nullable();
            $table->date('license_issued_on')->nullable();
            $table->string('license_type', 8)->nullable();
            $table->date('license_expires_on')->nullable();
            $table->boolean('ignore_warranty')->default(false);
            $table->boolean('print_license')->default(false);
            $table->string('rf_id', 120)->nullable();
            $table->string('store_allowed', 16)->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('party_profile_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_profile_id')->constrained('party_profiles')->cascadeOnDelete();
            $table->string('type', 16);
            $table->timestamps();

            $table->unique(['party_profile_id', 'type']);
            $table->index(['type', 'party_profile_id']);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->foreignId('party_profile_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('party_profiles')
                ->restrictOnDelete();
            $table->unique('party_profile_id');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('party_profile_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('party_profiles')
                ->restrictOnDelete();
            $table->unique('party_profile_id');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('salesman_party_profile_id')
                ->nullable()
                ->after('salesman_membership_id')
                ->constrained('party_profiles')
                ->restrictOnDelete();
            $table->index(['tenant_id', 'salesman_party_profile_id']);
        });

        $copy = function (object $row): array {
            return [
                'ulid' => (string) Str::ulid(),
                'tenant_id' => $row->tenant_id,
                'code' => $row->code,
                'name' => $row->name,
                'deals_in' => $row->deals_in ?? null,
                'contact_person' => $row->contact_person ?? null,
                'mobile' => $row->mobile ?? null,
                'mobile_secondary' => $row->mobile_secondary ?? null,
                'phone' => $row->phone ?? null,
                'phone_secondary' => $row->phone_secondary ?? null,
                'email' => $row->email ?? null,
                'address' => $row->address ?? null,
                'billing_address' => $row->billing_address ?? null,
                'area' => $row->area ?? null,
                'invoice_restricted' => (bool) ($row->invoice_restricted ?? false),
                'credit_limit_amount' => $row->credit_limit_amount ?? 0,
                'credit_limit_days' => $row->credit_limit_days ?? 0,
                'add_percent' => $row->add_percent ?? 0,
                'cnic' => $row->cnic ?? null,
                'ntn' => $row->ntn ?? null,
                'stn' => $row->stn ?? null,
                'formulas' => $row->formulas ?? null,
                'license_number' => $row->license_number ?? null,
                'license_issued_on' => $row->license_issued_on ?? null,
                'license_type' => $row->license_type ?? null,
                'license_expires_on' => $row->license_expires_on ?? null,
                'ignore_warranty' => (bool) ($row->ignore_warranty ?? false),
                'print_license' => (bool) ($row->print_license ?? false),
                'rf_id' => $row->rf_id ?? null,
                'store_allowed' => $row->store_allowed ?? null,
                'image_path' => $row->image_path ?? null,
                'is_active' => (bool) ($row->is_active ?? true),
                'created_by' => $row->created_by ?? null,
                'updated_by' => $row->updated_by ?? null,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ];
        };

        DB::table('suppliers')->orderBy('id')->chunkById(200, function ($rows) use ($copy): void {
            foreach ($rows as $row) {
                $profileId = DB::table('party_profiles')->insertGetId($copy($row));
                DB::table('party_profile_types')->insert([
                    'party_profile_id' => $profileId,
                    'type' => 'vendor',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('suppliers')->where('id', $row->id)->update(['party_profile_id' => $profileId]);
            }
        });

        DB::table('customers')->orderBy('id')->chunkById(200, function ($rows) use ($copy): void {
            foreach ($rows as $row) {
                $profileId = DB::table('party_profiles')->insertGetId($copy($row));
                DB::table('party_profile_types')->insert([
                    'party_profile_id' => $profileId,
                    'type' => 'customer',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('customers')->where('id', $row->id)->update(['party_profile_id' => $profileId]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'salesman_party_profile_id']);
            $table->dropConstrainedForeignId('salesman_party_profile_id');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['party_profile_id']);
            $table->dropConstrainedForeignId('party_profile_id');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropUnique(['party_profile_id']);
            $table->dropConstrainedForeignId('party_profile_id');
        });

        Schema::dropIfExists('party_profile_types');
        Schema::dropIfExists('party_profiles');
    }
};
