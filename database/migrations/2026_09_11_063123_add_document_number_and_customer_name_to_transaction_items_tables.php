<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Reports page shows one row per line item (SKU-level), unlike Zoho's
 * own invoice/order lists which are one row per document — these columns
 * let a row be traced back to which document/customer it came from without
 * changing the line-item grain of the table.
 */
return new class extends Migration
{
    private const TABLES = ['sale_items', 'purchase_order_items', 'return_items', 'invoice_items'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('document_number')->nullable()->after('zoho_line_item_id');
                $blueprint->string('customer_name')->nullable()->after('document_number');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn(['document_number', 'customer_name']);
            });
        }
    }
};
