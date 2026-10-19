<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Zoho invoice DOCUMENT (not line item) — sourced from the
 * org's Zoho Analytics "Invoices" table (49 columns, ~42k rows, confirmed
 * live 2026-09-22), a different table from the one behind invoice_items
 * (which is line-item level and has no customer id or balance at all).
 *
 * This table exists specifically to close two gaps found while building the
 * customer detail page: (1) invoice_items.customer_name is free text with
 * no reliable id to join on; this table's `zoho_customer_id` matches
 * customers.zoho_contact_id exactly, and (2) there was no real outstanding
 * balance anywhere in the system — `balance` here is Zoho's own per-invoice
 * balance, summable per customer for a real AR figure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_documents', function (Blueprint $table) {
            $table->id();

            $table->string('zoho_invoice_id')->unique();
            $table->string('invoice_number')->nullable();
            $table->string('zoho_customer_id')->nullable();

            $table->date('invoice_date')->nullable();
            $table->date('due_date')->nullable();

            // Three different status-shaped fields, kept separate rather
            // than collapsed into one — confirmed live these can disagree
            // (e.g. invoice_status/status = "Closed" while final_status =
            // "OVERDUE" for the same row): "Closed" reads as the document's
            // own edit-lifecycle state, "OVERDUE" as a separately computed
            // collections/AR status. Flattening them would have silently
            // picked one meaning over the other without evidence for which
            // is right.
            $table->string('invoice_status')->nullable();
            $table->string('status')->nullable();
            $table->string('final_status')->nullable();

            $table->decimal('sub_total', 14, 2)->nullable();
            $table->decimal('discount_amount', 14, 2)->nullable();
            $table->decimal('adjustment', 14, 2)->nullable();
            $table->decimal('total', 14, 2)->nullable();
            $table->decimal('balance', 14, 2)->nullable();
            $table->decimal('balance_fcy', 14, 2)->nullable();
            $table->decimal('write_off_amount', 14, 2)->nullable();

            $table->string('salesperson_id')->nullable();
            $table->string('location_id')->nullable();
            $table->string('purchase_order_number')->nullable();

            $table->integer('age_in_days')->nullable();
            $table->string('age_tier')->nullable();

            // dateTime, not timestamp — a TIMESTAMP column interprets the
            // given string against the MySQL session's timezone and
            // validates it against that zone's DST rules; confirmed live
            // (2026-09-22) that this host's MySQL timezone rejected a
            // perfectly valid Zoho datetime ("2025-03-09 02:00:55") because
            // that exact wall-clock hour never existed in the session's
            // (US) timezone on the day US clocks sprang forward. DATETIME
            // stores the literal value with no timezone interpretation, so
            // it can't hit this.
            $table->dateTime('zoho_created_time')->nullable();
            $table->dateTime('zoho_last_modified_time')->nullable();

            $table->json('extra')->nullable();

            $table->timestamps();

            $table->index('zoho_customer_id');
            $table->index('invoice_number');
            $table->index('invoice_date');
            $table->index('final_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_documents');
    }
};
