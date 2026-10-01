<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('column_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            /**
             * Scope:
             * - user  → one layout per user (primary persistence)
             * - role  → default layout for a role (fallback / shared baseline)
             */
            $table->string('scope', 16);
            $table->unsignedBigInteger('scope_id');
            $table->string('screen_key', 64);
            /** @var list<array{key:string,visible:bool,position:int,width:?int,locked:bool}> */
            $table->json('layout');
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'scope', 'scope_id', 'screen_key'],
                'column_preferences_tenant_scope_screen_unique',
            );
            $table->index(['tenant_id', 'screen_key'], 'column_preferences_tenant_screen_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('column_preferences');
    }
};
