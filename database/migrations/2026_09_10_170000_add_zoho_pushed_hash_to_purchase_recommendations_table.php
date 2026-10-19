<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks a hash of the fields last SUCCESSFULLY pushed to Zoho, separate
 * from the row's latest computed values (which persistLocally() always
 * overwrites, success or not). Without this, a kill/failure mid-push would
 * leave a SKU's local row showing the new values while Zoho never actually
 * got them — and the next run's diff check, comparing against that local
 * row, would wrongly think nothing changed and skip retrying it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_recommendations', function (Blueprint $table) {
            $table->string('zoho_pushed_hash')->nullable()->after('value');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_recommendations', function (Blueprint $table) {
            $table->dropColumn('zoho_pushed_hash');
        });
    }
};
