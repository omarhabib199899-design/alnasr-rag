<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (connection, transaction type) tracking the newest
 * last_modified_time seen so far. Lets a sync run fetch only what changed
 * since last time instead of re-pulling full history every run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zoho_sync_cursors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zoho_connection_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->timestamp('last_modified_watermark')->nullable();
            $table->timestamps();

            $table->unique(['zoho_connection_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zoho_sync_cursors');
    }
};
