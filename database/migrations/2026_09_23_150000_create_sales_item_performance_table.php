<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw per-line-item transaction detail (invoices + credit notes), source:
 * Sales_Item_Performance.csv (766k+ rows) — one row per item per invoice,
 * unlike sales_item_monthly_performance which is already aggregated by
 * month. Kept raw so invoice-level totals (date, amount, gross profit, GP%)
 * can be aggregated per transaction_number at query time, matching the
 * "Invoice Profit" table in the manager's Zoho Analytics dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_item_performance', function (Blueprint $table) {
            $table->id();
            $table->string('zoho_transaction_id')->nullable();
            $table->string('transaction_number');
            $table->string('transaction_type', 50)->nullable();
            $table->date('transaction_date')->nullable();
            $table->string('zoho_customer_id');
            $table->string('zoho_item_id')->nullable();
            $table->string('item_name')->nullable();
            $table->string('zoho_location_id')->nullable();
            $table->decimal('qty', 14, 2)->nullable();
            $table->string('batch')->nullable();
            $table->string('expiry')->nullable();
            $table->decimal('item_price', 14, 2)->nullable();
            $table->decimal('discount_amount', 14, 2)->nullable();
            $table->decimal('cogs', 14, 2)->nullable();
            $table->decimal('item_total', 14, 2)->nullable();
            $table->decimal('item_gross_profit', 14, 2)->nullable();
            // Wider than a plain percentage range — same overflow issue seen
            // in sales_item_monthly_performance.discount_percent applies here.
            $table->decimal('discount_percent', 10, 2)->nullable();
            $table->timestamps();

            $table->index('zoho_customer_id');
            $table->index('transaction_number');
            $table->index('transaction_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_item_performance');
    }
};
