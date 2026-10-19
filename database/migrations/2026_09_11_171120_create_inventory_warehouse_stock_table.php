<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-SKU, per-warehouse stock — filled from manual "Stock Summary Report"
 * exports (one per location filter), since Zoho's list-endpoint inventory
 * sync doesn't include the locations breakdown (only the per-item detail
 * endpoint does, and fetching that for all ~6,300 items would compete with
 * the invoices backfill's API budget — see PurchaseRecommendationBuilder's
 * docblock for the same tradeoff on a different field).
 *
 * Not tied to any Import row — this isn't a synced/versioned dataset, it's
 * a standing snapshot that gets overwritten in place whenever a fresher
 * export is imported for that warehouse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_warehouse_stock', function (Blueprint $table) {
            $table->id();
            $table->string('sku');
            $table->string('warehouse');
            $table->string('item_name')->nullable();
            $table->integer('closing_stock')->default(0);
            $table->timestamps();

            $table->unique(['sku', 'warehouse']);
            $table->index('warehouse');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_warehouse_stock');
    }
};
