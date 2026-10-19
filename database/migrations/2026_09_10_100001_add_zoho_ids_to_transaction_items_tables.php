<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the Zoho sync path upsert transaction line items instead of wiping
 * and re-inserting full history on every run. Excel-imported rows leave
 * these null — MySQL treats each NULL as distinct in a unique index, so
 * they never collide with each other or with Zoho-sourced rows.
 */
return new class extends Migration
{
    private const TABLES = ['sale_items', 'purchase_order_items', 'return_items', 'invoice_items'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->string('zoho_document_id')->nullable()->after('import_id');
                $blueprint->string('zoho_line_item_id')->nullable()->after('zoho_document_id');
                $blueprint->unique(['zoho_document_id', 'zoho_line_item_id'], $table.'_zoho_unique');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropUnique($table.'_zoho_unique');
                $blueprint->dropColumn(['zoho_document_id', 'zoho_line_item_id']);
            });
        }
    }
};
