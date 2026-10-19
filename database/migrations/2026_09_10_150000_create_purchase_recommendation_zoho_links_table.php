<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers which Zoho custom-module record id corresponds to which SKU,
 * so re-running the push updates existing records instead of creating
 * duplicates — without needing to search Zoho for every SKU on every run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_recommendation_zoho_links', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('zoho_record_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_recommendation_zoho_links');
    }
};
