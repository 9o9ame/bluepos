<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table): void {
            $table->foreignId('purchase_invoice_id')->nullable()->change();
        });

        Schema::table('purchase_return_lines', function (Blueprint $table): void {
            $table->foreignId('purchase_invoice_line_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        $hasGeneralReturns = DB::table('purchase_returns')
            ->whereNull('purchase_invoice_id')
            ->exists();

        $hasGeneralLines = DB::table('purchase_return_lines')
            ->whereNull('purchase_invoice_line_id')
            ->exists();

        if ($hasGeneralReturns || $hasGeneralLines) {
            throw new \RuntimeException(
                'Cannot restore mandatory purchase invoice references while general purchase returns exist.'
            );
        }

        Schema::table('purchase_return_lines', function (Blueprint $table): void {
            $table->foreignId('purchase_invoice_line_id')->nullable(false)->change();
        });

        Schema::table('purchase_returns', function (Blueprint $table): void {
            $table->foreignId('purchase_invoice_id')->nullable(false)->change();
        });
    }
};
