<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Stock Movement report import already had this data (the first of the
 * two duplicate "From Location Name" columns) but it was discarded — only
 * the second occurrence (the mislabeled contact name) was captured. Adding
 * it now so per-branch sales velocity can be computed for the manager's
 * transfer-suggestion ask ("which items are overstocked in Riyadh because
 * of a transfer, and should we do a trial transfer elsewhere").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('warehouse')->nullable()->after('customer_name');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('warehouse');
        });
    }
};
