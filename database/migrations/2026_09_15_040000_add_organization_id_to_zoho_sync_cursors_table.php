<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zoho_sync_cursors', function (Blueprint $table) {
            $table->string('zoho_organization_id')->nullable()->after('zoho_connection_id');
        });

        // Backfill from each cursor's current connection so existing
        // progress carries over to the new key — see SyncZohoTransactionTypeJob
        // for why this column replaces zoho_connection_id as the lookup key.
        DB::statement('
            UPDATE zoho_sync_cursors c
            JOIN zoho_connections conn ON conn.id = c.zoho_connection_id
            SET c.zoho_organization_id = conn.zoho_organization_id
            WHERE conn.zoho_organization_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::table('zoho_sync_cursors', function (Blueprint $table) {
            $table->dropColumn('zoho_organization_id');
        });
    }
};
