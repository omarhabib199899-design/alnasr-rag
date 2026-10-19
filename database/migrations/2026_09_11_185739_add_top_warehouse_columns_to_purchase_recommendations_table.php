<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Summarizes inventory_warehouse_stock (manually imported per-branch data —
 * see ImportWarehouseStockReport) down to one row per SKU, so it fits the
 * same push-to-Zoho pipeline as everything else in this report. The
 * existing 'warehouse' column stays untouched — it's fed from the live API
 * sync (always null today, since Zoho's list-endpoint items don't carry
 * locations) and is conceptually different from this manually-sourced data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_recommendations', function (Blueprint $table) {
            $table->string('top_warehouse_name')->nullable()->after('warehouse');
            $table->unsignedInteger('top_warehouse_stock')->nullable()->after('top_warehouse_name');
            $table->unsignedInteger('warehouse_count')->nullable()->after('top_warehouse_stock');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_recommendations', function (Blueprint $table) {
            $table->dropColumn(['top_warehouse_name', 'top_warehouse_stock', 'warehouse_count']);
        });
    }
};
