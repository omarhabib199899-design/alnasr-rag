<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sfda_changes', function (Blueprint $table) {
            $table->id();
            $table->string('sku');
            $table->string('item_name')->nullable();
            $table->string('field_changed');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('detected_at');
            $table->timestamps();

            $table->index(['sku', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sfda_changes');
    }
};
