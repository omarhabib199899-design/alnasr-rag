<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Zoho contact ("Customers" table in the org's existing Zoho
 * Analytics workspace — same source already used for the invoice/sales
 * historical backfills, see ImportInvoiceItemsFixedReport etc.). First
 * populated via a one-time manual Excel export/import
 * (ImportCustomersAnalyticsReport), same pattern as those backfills; a live
 * incremental Zoho Contacts API sync is a planned fast-follow once the
 * Contacts OAuth scope is added (see ZohoOAuthClient's SCOPE comment).
 *
 * Column choices mirror the real 82-column Analytics export exactly
 * (confirmed live 2026-09-22, not guessed) — frequently
 * searched/filtered/sorted business fields get their own column, the rest
 * (10 branch location/city pairs, 12 monthly sales-target and 12 monthly
 * collection-target columns, a handful of finance/promissory-note fields)
 * live in `extra` json, same pattern as invoice_items.extra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            // "Customer ID" in the export — Zoho's own contact id, the join
            // key for the eventual zoho_customer_id column on
            // invoice_items/sale_items/return_items.
            $table->string('zoho_contact_id')->unique();
            $table->string('customer_name');
            $table->string('display_name')->nullable();
            $table->string('status')->nullable();
            $table->string('company_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('customer_number')->nullable();
            $table->string('city')->nullable();

            // Sales/account ownership — the export's own "Rep Name" and
            // "Sales Owner" columns turned out to already exist here, ahead
            // of the rep/supervisor/CEO hierarchy work that was explicitly
            // deferred — kept as plain strings for now, not a real relation.
            $table->string('rep_name')->nullable();
            $table->string('sales_owner')->nullable();
            $table->string('account_type')->nullable();

            // credit_limit/opening_balance are genuine "SAR X,XXX.XX"
            // amounts (parsed to decimal on import). accounts_receivable and
            // payments_due are NOT amounts despite the name — confirmed live
            // (2026-09-21) the "Accounts Receivable" export column actually
            // holds a 16-digit Zoho-internal reference id, identical across
            // every customer, which overflowed decimal(14,2) on the very
            // first import row — kept as plain strings until their real
            // meaning is confirmed.
            $table->decimal('credit_limit', 14, 2)->nullable();
            $table->string('accounts_receivable')->nullable();
            $table->string('payments_due')->nullable();
            $table->decimal('opening_balance', 14, 2)->nullable();

            // Regulatory/compliance — directly relevant to this system's
            // existing SFDA compliance tracking (see sfda_changes/
            // sfda_price_checks), not just generic contact fields.
            $table->string('cr_no')->nullable();
            $table->string('cr_status')->nullable();
            $table->date('cr_expiry_date')->nullable();
            $table->string('tax_registration_number')->nullable();
            $table->string('gln_no')->nullable();
            $table->date('moh_sfda_license_expiry_date')->nullable();
            $table->string('reconciliation_status')->nullable();
            $table->date('last_reconciliation_date')->nullable();
            $table->string('attested_account')->nullable();
            $table->string('portal_enabled')->nullable();
            $table->string('warehouse_city')->nullable();

            // Zoho's own timestamps, separate from our created_at/updated_at
            // — last_modified_time is also the field a future live sync's
            // watermark would compare against.
            $table->timestamp('zoho_created_time')->nullable();
            $table->timestamp('zoho_last_modified_time')->nullable();

            $table->json('extra')->nullable();

            $table->timestamps();

            $table->index('customer_name');
            $table->index('display_name');
            $table->index('status');
            $table->index('city');
            $table->index('rep_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
