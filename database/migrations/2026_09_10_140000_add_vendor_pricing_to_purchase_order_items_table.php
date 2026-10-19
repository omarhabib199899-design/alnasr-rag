<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Needed for the Purchase Recommendation report: comparing vendors per SKU
 * (lowest price, best discount, most purchased) requires knowing which
 * vendor and at what rate/discount each historical purchase order line was
 * at — none of which the original sync captured.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->string('vendor_name')->nullable()->after('sku');
            $table->decimal('rate', 12, 2)->nullable()->after('vendor_name');
            $table->decimal('discount_percent', 5, 2)->nullable()->after('rate');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn(['vendor_name', 'rate', 'discount_percent']);
        });
    }
};
