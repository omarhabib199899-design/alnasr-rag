<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local copy of whatever PurchaseRecommendationBuilder last computed —
 * lets the site's own Reports page show this data without depending on
 * Zoho being reachable, and without recomputing on every page load.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_recommendations', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('item_name')->nullable();
            $table->integer('current_stock')->default(0);
            $table->integer('expired_quantity')->nullable();
            $table->integer('pending_po')->default(0);
            $table->integer('total_available')->default(0);
            $table->integer('suggested_purchase')->default(0);
            $table->string('stock_status');
            $table->decimal('price', 12, 2)->nullable();
            $table->string('best_discount_vendor')->nullable();
            $table->decimal('best_discount_price', 12, 2)->nullable();
            $table->string('lowest_price_vendor')->nullable();
            $table->decimal('lowest_price_value', 12, 2)->nullable();
            $table->string('most_purchased_vendor')->nullable();
            $table->decimal('most_purchased_price', 12, 2)->nullable();
            $table->decimal('value', 14, 2)->nullable();
            $table->timestamps();

            $table->index('stock_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_recommendations');
    }
};
