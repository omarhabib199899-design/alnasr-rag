<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sfda_item_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('item_name')->nullable();
            $table->string('sfda_reg_no');
            $table->timestamp('last_modification_time')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->string('storage_conditions')->nullable();
            $table->string('legal_status')->nullable();
            $table->string('marketing_status')->nullable();
            $table->string('authorization_status')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sfda_item_snapshots');
    }
};
