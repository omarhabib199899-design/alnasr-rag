<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained()->cascadeOnDelete();
            $table->string('sku');
            $table->integer('quantity')->default(0);
            $table->date('transaction_date');
            $table->string('status')->default('processed'); // holds a reason if the sheet has one (defective, wrong item, ...)
            $table->json('extra')->nullable();
            $table->timestamps();

            $table->index('sku');
            $table->index('transaction_date');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('return_items');
    }
};
