<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Items whose extra.sfda_reg_no resolves to a DIFFERENT drug entirely at
 * SFDA (not a price mismatch — a wrong registration number recorded on the
 * item in Zoho itself). Confirmed live 2026-10-07: EBETAXEL (SKU 166,
 * scientific name PACLITAXEL) has SFDA NO 28-355-02 on file in Zoho, which
 * SFDA's own database returns as "SEDACORON 200MG TAB" (amiodarone, an
 * unrelated cardiac drug) — not a sync bug on our side (confirmed directly
 * against the live Zoho Inventory item screen), a data-entry error upstream
 * that needs correcting in Zoho. SfdaPriceCheckJob writes here instead of
 * sfda_price_checks for any item whose name shares no real word with
 * SFDA's tradeName, rather than reporting a price "difference" that's
 * actually two unrelated drugs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sfda_reg_no_issues', function (Blueprint $table) {
            $table->id();
            $table->string('sku');
            $table->string('item_name');
            $table->string('sfda_reg_no');
            $table->string('sfda_trade_name')->nullable();
            $table->timestamp('detected_at');
            $table->timestamps();

            $table->unique('sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sfda_reg_no_issues');
    }
};
