<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns for the manager's "planning" insights (6-month movement, estimated
 * profit, per-warehouse overstock, customer reach). See
 * PurchaseRecommendationBuilder's docblock for which of these are exact vs
 * approximate, and which manager-requested metrics (stockout frequency,
 * pharmacy-only ranking) aren't here yet because the underlying data isn't
 * synced from Zoho at all — not a matter of adding a column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_recommendations', function (Blueprint $table) {
            $table->integer('sold_6mo')->default(0)->after('current_stock');
            $table->decimal('cost', 12, 2)->nullable()->after('price');
            $table->decimal('estimated_profit_6mo', 14, 2)->nullable()->after('cost');
            $table->string('warehouse')->nullable()->after('item_name');
            $table->unsignedInteger('customer_count_6mo')->nullable()->after('sold_6mo');
            $table->unsignedInteger('top_customer_order_qty')->nullable()->after('customer_count_6mo');
            $table->string('top_customer_name')->nullable()->after('top_customer_order_qty');
            // Name-pattern heuristic (no real customer-type data is synced
            // from Zoho yet) — see PurchaseRecommendationBuilder::looksLikePharmacy.
            $table->unsignedInteger('top_pharmacy_order_qty')->nullable()->after('top_customer_name');
            $table->string('top_pharmacy_name')->nullable()->after('top_pharmacy_order_qty');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_recommendations', function (Blueprint $table) {
            $table->dropColumn([
                'sold_6mo', 'cost', 'estimated_profit_6mo', 'warehouse',
                'customer_count_6mo', 'top_customer_order_qty', 'top_customer_name',
                'top_pharmacy_order_qty', 'top_pharmacy_name',
            ]);
        });
    }
};
