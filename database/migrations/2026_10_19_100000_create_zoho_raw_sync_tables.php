<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bookkeeping for SyncZohoRawTables (the zoho_raw_* tables themselves are
 * created by that command from each Zoho Analytics view's own column
 * metadata, since their shape is Zoho's, not ours).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zoho_raw_sync_state', function (Blueprint $table) {
            $table->string('table_key', 64)->primary();
            $table->timestamp('last_full_at')->nullable();
            $table->timestamp('last_incremental_at')->nullable();
            // Max "Last Modified Time" value seen in Zoho's data (Zoho's own clock/timezone, stored as-is).
            $table->dateTime('watermark')->nullable();
            $table->unsignedBigInteger('live_rows')->nullable();
            $table->timestamps();
        });

        Schema::create('zoho_raw_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('table_key', 64)->index();
            $table->string('kind', 16); // full | incremental | compute
            $table->string('status', 16); // running | succeeded | failed
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('exported_rows')->nullable();
            $table->unsignedBigInteger('written_rows')->nullable();
            $table->text('error')->nullable();
            $table->index(['table_key', 'status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zoho_raw_sync_runs');
        Schema::dropIfExists('zoho_raw_sync_state');
    }
};
