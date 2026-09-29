<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_types', function (Blueprint $table) {
            $table->string('code', 64)->nullable()->after('sub_head_id');
            $table->unique(['tenant_id', 'code'], 'account_types_tenant_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('account_types', function (Blueprint $table) {
            $table->dropUnique('account_types_tenant_code_unique');
            $table->dropColumn('code');
        });
    }
};
