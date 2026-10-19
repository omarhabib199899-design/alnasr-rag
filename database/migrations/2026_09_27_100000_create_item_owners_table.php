<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item → Owner (Purchaser) mapping, source: a Zoho Analytics "Items" query
 * table (storage/app/Items.xlsx export, columns confirmed live 2026-09-27:
 * Item ID/SKU/OWNER among others) — a different table from the live Zoho
 * Inventory Items API that already feeds inventory_items, so kept as its
 * own table rather than bolted onto inventory_items. zoho_item_id is what
 * joins to sales_item_performance.zoho_item_id for the Item Performance
 * tab's per-Owner breakdown (per Dr. Ahmed's voice note, 2026-09-27: "Owner"
 * = the Purchaser responsible for that item's SKUs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_owners', function (Blueprint $table) {
            $table->id();
            $table->string('zoho_item_id')->unique();
            $table->string('sku')->nullable();
            $table->string('item_name')->nullable();
            $table->string('owner')->nullable();
            $table->timestamps();

            $table->index('sku');
            $table->index('owner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_owners');
    }
};
