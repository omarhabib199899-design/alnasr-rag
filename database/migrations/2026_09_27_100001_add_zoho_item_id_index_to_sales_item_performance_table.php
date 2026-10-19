<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Item Performance tab (buildItemPerformanceData in CustomerController)
 * joins sales_item_performance to item_owners by zoho_item_id, per-customer
 * — same lesson as the sale_items/return_items customer_name indexes added
 * 2026-09-26 after a live 504: this table has ~766k+ rows and zoho_item_id
 * had no index at all, so add it before the new query pattern ships, not
 * after it times out in production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_item_performance', function (Blueprint $table) {
            $table->index('zoho_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_item_performance', function (Blueprint $table) {
            $table->dropIndex(['zoho_item_id']);
        });
    }
};
