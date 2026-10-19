<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holds the complete raw response from Zoho's live Contacts API detail
 * endpoint (GET /contacts/{id}), captured verbatim rather than parsed into
 * columns immediately — this project's standing practice (see e.g.
 * ImportInvoiceDocumentsAnalyticsReport's docblock): confirm real field
 * names against a live response before writing extraction logic, never
 * guess. Populated by ZohoContactSyncJob once the ZohoInventory.contacts.READ
 * scope is live (requires a reconnect — see ZohoOAuthClient's SCOPE
 * comment). Precise columns for address/owner/currency/tax treatment/
 * approval status get added in a follow-up migration once this has been
 * inspected against real data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->json('live_contact_data')->nullable()->after('extra');
            $table->dateTime('live_contact_synced_at')->nullable()->after('live_contact_data');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['live_contact_data', 'live_contact_synced_at']);
        });
    }
};
