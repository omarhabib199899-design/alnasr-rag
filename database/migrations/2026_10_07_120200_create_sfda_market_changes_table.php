<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Day-over-day change log across SFDA's ENTIRE drug database (not just our
 * own inventory — see sfda_market_snapshots) — one row per field that
 * differs between a reg_no's snapshot yesterday vs today. Appended, never
 * truncated, same "running log" pattern as sfda_changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sfda_market_changes', function (Blueprint $table) {
            $table->id();
            $table->string('sfda_reg_no');
            $table->text('trade_name')->nullable();
            $table->string('field_changed');
            // text, not string — same reason as sfda_market_snapshots'
            // storage_conditions/etc: these just copy whatever value came
            // from that table, which can exceed 255 chars.
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->date('detected_date');
            $table->timestamps();

            $table->index(['detected_date', 'field_changed']);
            $table->index('sfda_reg_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sfda_market_changes');
    }
};
