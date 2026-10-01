<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['suppliers', 'customers', 'accounts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->decimal('add_percent', 12, 8)->default(0)->after('credit_limit_days');
                $table->string('cnic', 32)->nullable()->after('add_percent');
                $table->string('ntn', 64)->nullable()->after('cnic');
                $table->string('stn', 64)->nullable()->after('ntn');
                $table->text('formulas')->nullable()->after('stn');
            });
        }
    }

    public function down(): void
    {
        foreach (['suppliers', 'customers', 'accounts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['add_percent', 'cnic', 'ntn', 'stn', 'formulas']);
            });
        }
    }
};
