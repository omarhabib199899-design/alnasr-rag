<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (sfda_reg_no, snapshot_date) — the FULL SFDA drug database
 * (~9,165 items, not just items in our own inventory), pulled daily via
 * SFDA's own bulk listing page (oldsfda.sfda.gov.sa/GetDrugs.php with a
 * blank RegNo — confirmed live 2026-10-07 this returns a paginated listing
 * of every registered drug, 459 pages x 20/page). Insert-only, never
 * overwritten — each day's full snapshot is kept permanently so any two
 * days can be diffed, not just "today vs the last time we checked".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sfda_market_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('sfda_reg_no');
            $table->text('trade_name')->nullable();
            $table->decimal('price', 12, 3)->nullable();
            // text, not string — confirmed live 2026-10-07: SFDA's own data
            // occasionally has storage-condition text well past 255 chars,
            // killed a batch with a truncation error on the first real run.
            // Widened all the free-text fields from this same source
            // uniformly rather than guessing which ones are "safe" at 255.
            $table->text('storage_conditions')->nullable();
            $table->text('legal_status')->nullable();
            $table->text('marketing_status')->nullable();
            $table->text('authorization_status')->nullable();
            $table->text('agent_name')->nullable();
            $table->date('snapshot_date');
            $table->timestamps();

            $table->unique(['sfda_reg_no', 'snapshot_date'], 'sfda_market_snap_reg_date_uq');
            $table->index('snapshot_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sfda_market_snapshots');
    }
};
