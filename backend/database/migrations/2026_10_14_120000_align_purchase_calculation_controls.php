<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table): void {
            $table->string('discount_apply_on', 20)->default('trade')->after('calculation_method');
            $table->jsonb('calculation_modes')->nullable()->after('discount_apply_on');
            $table->boolean('auto_calc_mrp')->default(true)->after('calculation_modes');
            $table->boolean('withholding_is_pct')->default(false)->after('auto_calc_mrp');
            $table->decimal('withholding_pct', 12, 8)->default(0)->after('withholding_is_pct');
        });

        Schema::table('purchase_invoice_lines', function (Blueprint $table): void {
            $table->decimal('regular_disc_amount', 20, 4)->default(0)->after('regular_disc_pct');
            $table->decimal('special_disc_amount', 20, 4)->default(0)->after('special_disc_pct');
            $table->decimal('disc_after_gst_pct', 12, 8)->default(0)->after('special_disc_amount');
            $table->decimal('disc_after_gst_amount', 20, 4)->default(0)->after('disc_after_gst_pct');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoice_lines', function (Blueprint $table): void {
            $table->dropColumn([
                'regular_disc_amount',
                'special_disc_amount',
                'disc_after_gst_pct',
                'disc_after_gst_amount',
            ]);
        });

        Schema::table('purchase_invoices', function (Blueprint $table): void {
            $table->dropColumn([
                'discount_apply_on',
                'calculation_modes',
                'auto_calc_mrp',
                'withholding_is_pct',
                'withholding_pct',
            ]);
        });
    }
};
