<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('code', 32)->nullable();
        });

        $rows = DB::table('tenants')->select('id', 'slug', 'name')->orderBy('id')->get();
        $used = [];

        foreach ($rows as $row) {
            $base = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $row->slug));
            if ($base === '') {
                $base = 'T'.(string) $row->id;
            }
            $code = $base;
            $i = 2;
            while (isset($used[$code])) {
                $code = $base.$i;
                $i++;
            }
            $used[$code] = true;
            DB::table('tenants')->where('id', $row->id)->update(['code' => $code]);
        }

        DB::statement('ALTER TABLE tenants ALTER COLUMN code SET NOT NULL');

        Schema::table('tenants', function (Blueprint $table) {
            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
