<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained()->cascadeOnDelete();
            $table->string('sku');
            $table->integer('quantity')->default(0);
            $table->date('invoice_date');
            $table->string('status')->default('completed');
            $table->json('extra')->nullable();
            $table->timestamps();

            $table->index('sku');
            $table->index('invoice_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
