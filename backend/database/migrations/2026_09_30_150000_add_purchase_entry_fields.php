<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table): void {
            $table->string('po_number', 100)->nullable()->after('supplier_invoice_number');
            $table->string('invoice_type', 40)->default('tax_gst')->after('po_number');
            $table->string('currency_code', 3)->default('PKR')->after('invoice_type');
            $table->string('calculation_method', 40)->default('gst_on_trade')->after('currency_code');
            $table->decimal('default_sales_tax_pct', 12, 8)->default(0)->after('calculation_method');
            $table->decimal('default_further_tax_pct', 12, 8)->default(0)->after('default_sales_tax_pct');
            $table->decimal('default_advance_tax_pct', 12, 8)->default(0)->after('default_further_tax_pct');
            $table->string('default_price_type', 20)->default('trade')->after('default_advance_tax_pct');
            $table->string('brand_label', 120)->nullable()->after('default_price_type');
            $table->decimal('loading_amount', 20, 4)->default(0)->after('freight_amount');
            $table->decimal('other_discount', 20, 4)->default(0)->after('other_charges');
            $table->decimal('trade_offer', 20, 4)->default(0)->after('other_discount');
            $table->decimal('advance_tax_amount', 20, 4)->default(0)->after('trade_offer');
            $table->decimal('round_off', 20, 4)->default(0)->after('advance_tax_amount');
            $table->decimal('further_tax_amount', 20, 4)->default(0)->after('tax_amount');
            $table->string('tax_type', 40)->default('standard')->after('notes');
            $table->string('payment_terms', 40)->default('credit')->after('tax_type');
        });

        Schema::table('purchase_invoice_lines', function (Blueprint $table): void {
            $table->string('brand_label', 120)->nullable()->after('notes');
            $table->string('hs_code', 40)->nullable()->after('brand_label');
            $table->string('pack_size', 40)->nullable()->after('hs_code');
            $table->decimal('qty_ctn', 20, 6)->default(0)->after('pack_size');
            $table->decimal('free_pcs', 20, 6)->default(0)->after('qty_ctn');
            $table->string('price_type', 20)->default('trade')->after('free_pcs');
            $table->decimal('mrp', 20, 4)->default(0)->after('price_type');
            $table->decimal('trade_disc_pct', 12, 8)->default(0)->after('mrp');
            $table->decimal('regular_disc_pct', 12, 8)->default(0)->after('trade_disc_pct');
            $table->decimal('special_disc_pct', 12, 8)->default(0)->after('regular_disc_pct');
            $table->decimal('tax_pct', 12, 8)->default(0)->after('special_disc_pct');
            $table->decimal('further_tax_pct', 12, 8)->default(0)->after('tax_pct');
            $table->decimal('further_tax_amount', 20, 4)->default(0)->after('tax_amount');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoice_lines', function (Blueprint $table): void {
            $table->dropColumn([
                'brand_label',
                'hs_code',
                'pack_size',
                'qty_ctn',
                'free_pcs',
                'price_type',
                'mrp',
                'trade_disc_pct',
                'regular_disc_pct',
                'special_disc_pct',
                'tax_pct',
                'further_tax_pct',
                'further_tax_amount',
            ]);
        });

        Schema::table('purchase_invoices', function (Blueprint $table): void {
            $table->dropColumn([
                'po_number',
                'invoice_type',
                'currency_code',
                'calculation_method',
                'default_sales_tax_pct',
                'default_further_tax_pct',
                'default_advance_tax_pct',
                'default_price_type',
                'brand_label',
                'loading_amount',
                'other_discount',
                'trade_offer',
                'advance_tax_amount',
                'round_off',
                'further_tax_amount',
                'tax_type',
                'payment_terms',
            ]);
        });
    }
};
