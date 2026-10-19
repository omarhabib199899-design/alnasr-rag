<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Line-item detail for Credit Notes — sourced from Zoho Analytics'
 * "Credit Note Items" + "Credit Notes" views (confirmed live 2026-10-15:
 * Credit Note Items has no credit note NUMBER, only the internal
 * "CreditNotes ID" — joined in PHP against Credit Notes' own export to
 * resolve document_number, which matches ar_ledger_entries.transaction_number
 * exactly, e.g. "CN-06310" — same number CreditNotesController already
 * shows). Avoids the new ZohoInventory.creditnotes.READ REST scope/
 * reconnect entirely; both source views were already reachable under the
 * existing Analytics scope.
 *
 * Full-replace (staging + swap) on every sync, same as ar_ledger_entries/
 * sales_item_performance — this is a complete Analytics snapshot each run,
 * not an incremental REST crawl, so there's no watermark/cursor to maintain
 * and no deletion-detection gap to worry about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_note_items', function (Blueprint $table) {
            $table->id();
            $table->string('zoho_document_id')->nullable(); // CreditNotes ID
            $table->string('document_number')->nullable(); // Credit Note Number, e.g. CN-06310
            $table->string('customer_name')->nullable();
            $table->string('sku')->nullable();
            $table->string('warehouse')->nullable();
            $table->integer('quantity')->nullable();
            $table->date('transaction_date')->nullable();
            $table->string('status')->nullable();
            $table->json('extra')->nullable();
            $table->timestamps();

            $table->index('document_number');
            $table->index('sku');
            $table->index('transaction_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_items');
    }
};
