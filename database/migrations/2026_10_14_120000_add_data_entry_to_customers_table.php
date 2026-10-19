<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Data Entry" — same story as sales_owner: a real Zoho custom field
 * (cf_data_entry) the live contact sync never captured, confirmed live
 * 2026-10-14 against CUS-1517 ("Walid Metwaly") after the manager flagged
 * this column exists in his own Excel/Analytics export. Only ~3.4%-scale
 * population expected at first, same as sales_owner — this is a rarely-set
 * field in Zoho, not a sync gap once ZohoContactMapper is updated to read
 * it (see that class's next commit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('data_entry')->nullable()->after('sales_owner');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('data_entry');
        });
    }
};
