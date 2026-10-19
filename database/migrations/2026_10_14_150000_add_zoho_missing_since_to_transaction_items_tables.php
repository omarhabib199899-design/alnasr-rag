<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same flag, same reasoning as customers.zoho_missing_since (see that
 * migration's docblock): SyncZohoTransactionTypeJob's incremental upsert has
 * no way to notice a document deleted in Zoho — it only ever adds/updates.
 * Set by the new ReconcileTransactions sweep per document (every line item
 * sharing that document's zoho_document_id gets the same value), cleared
 * back to null if the document reappears in a later sweep. Flag, never
 * delete — standing "never delete data" practice.
 */
return new class extends Migration
{
    private const TABLES = ['sale_items', 'purchase_order_items', 'return_items', 'invoice_items'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->timestamp('zoho_missing_since')->nullable()->after('zoho_line_item_id');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('zoho_missing_since');
            });
        }
    }
};
