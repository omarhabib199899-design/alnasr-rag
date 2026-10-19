<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Precise columns extracted from customers.live_contact_data (the raw Zoho
 * Contacts API detail payload — see that column's migration) once the real
 * field names were confirmed live 2026-09-22: owner_name, currency_code,
 * payment_terms_label, tax_treatment, cf_approval_status, billing_address,
 * shipping_address, and the API's own outstanding_receivable_amount (a
 * cross-check against the balance computed from invoice_documents — they
 * matched exactly on the first live contact synced, 4599.18 SAR both ways).
 *
 * Populated by reports:extract-contact-fields, which reads already-synced
 * live_contact_data locally — no extra Zoho API calls needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('owner_name')->nullable()->after('sales_owner');
            $table->string('currency_code')->nullable()->after('owner_name');
            $table->string('payment_terms_label')->nullable()->after('currency_code');
            $table->string('tax_treatment')->nullable()->after('payment_terms_label');
            $table->string('approval_status')->nullable()->after('tax_treatment');
            $table->json('billing_address')->nullable()->after('live_contact_synced_at');
            $table->json('shipping_address')->nullable()->after('billing_address');
            $table->decimal('live_outstanding_balance', 14, 2)->nullable()->after('shipping_address');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'owner_name', 'currency_code', 'payment_terms_label', 'tax_treatment',
                'approval_status', 'billing_address', 'shipping_address', 'live_outstanding_balance',
            ]);
        });
    }
};
