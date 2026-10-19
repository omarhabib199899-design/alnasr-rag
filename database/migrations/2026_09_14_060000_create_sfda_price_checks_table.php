<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot of items whose recorded selling price (inventory_items.extra.rsp)
 * disagrees with SFDA's own official regulated price for that drug's
 * registration number — mirrors the org's existing Deluge SFDA price-check
 * automation (which does the same comparison against
 * oldsfda.sfda.gov.sa/GetDrugs.php and writes mismatches into a Zoho custom
 * module), ported here so it's visible without opening Zoho.
 *
 * A standing snapshot, not a history log: CheckSfdaPrices truncates and
 * rewrites this table each run, same as the Deluge script deletes its prior
 * module records before writing today's — "what's out of line right now",
 * not "what's ever been out of line".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sfda_price_checks', function (Blueprint $table) {
            $table->id();
            $table->string('sku');
            $table->string('item_name')->nullable();
            $table->string('sfda_reg_no');
            $table->decimal('our_price', 12, 2)->nullable();
            $table->decimal('official_price', 12, 2)->nullable();
            $table->decimal('price_difference', 12, 2)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique('sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sfda_price_checks');
    }
};
