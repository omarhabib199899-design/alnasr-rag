<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Finance Dept Last Review Date" — a real Zoho custom field (cf_last_review_date)
 * confirmed live 2026-10-15 against a live contact detail response, never
 * previously captured by ZohoContactMapper. Added for the new Customer 360
 * "General" tab, which mirrors the fields from the one-time
 * Al_Nasr_Customer_Profile Excel mockup but only includes ones genuinely
 * present in Zoho's own Contacts data (confirmed live the same day —
 * PriceList ID, Promissory Note, branch locations, and monthly sales/
 * collection targets are NOT in Zoho's contact payload at all, so those
 * were deliberately left out rather than added as permanently-blank columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->date('finance_dept_last_review_date')->nullable()->after('last_reconciliation_date');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('finance_dept_last_review_date');
        });
    }
};
