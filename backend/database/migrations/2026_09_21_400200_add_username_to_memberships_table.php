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
        Schema::table('memberships', function (Blueprint $table) {
            $table->string('username', 64)->nullable();
            $table->unsignedInteger('security_version')->default(1);
            $table->boolean('mfa_required')->nullable();
        });

        $rows = DB::table('memberships as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->select('m.id', 'm.tenant_id', 'u.email', 'u.name')
            ->orderBy('m.id')
            ->get();

        $used = [];
        foreach ($rows as $row) {
            $base = strtolower((string) preg_replace('/[^a-z0-9._-]/i', '', (string) Str::before((string) $row->email, '@')));
            if ($base === '') {
                $base = 'user'.$row->id;
            }
            $username = $base;
            $i = 2;
            $key = $row->tenant_id.'|'.$username;
            while (isset($used[$key])) {
                $username = $base.$i;
                $key = $row->tenant_id.'|'.$username;
                $i++;
            }
            $used[$key] = true;
            DB::table('memberships')->where('id', $row->id)->update(['username' => $username]);
        }

        DB::statement('ALTER TABLE memberships ALTER COLUMN username SET NOT NULL');

        Schema::table('memberships', function (Blueprint $table) {
            $table->unique(['tenant_id', 'username']);
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'username']);
            $table->dropColumn(['username', 'security_version', 'mfa_required']);
        });
    }
};
