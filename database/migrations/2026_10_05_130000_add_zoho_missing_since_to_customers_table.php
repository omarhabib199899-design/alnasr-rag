<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Set by ReconcileCustomers when a periodic full-list sweep against Zoho's
 * live Contacts API can't find this customer's zoho_contact_id anymore
 * (deleted in Zoho, or genuinely never existed there) — never by the hourly
 * ZohoContactSyncJob. Cleared back to null the moment the contact reappears
 * in a later sweep. Deliberately a flag, not a delete — see this project's
 * standing "never delete data" practice; a human reviews flagged rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('zoho_missing_since')->nullable()->after('live_contact_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('zoho_missing_since');
        });
    }
};
