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
        Schema::create('collection_classification_staging', function (Blueprint $table) {
            $table->id();
            $table->string('zoho_customer_id');
            $table->string('customer_number')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('zoho_invoice_id');
            $table->string('closing_method')->nullable(); // only 'CLOSED BY PAYMENT' rows are used by the classification
            $table->date('settlement_date')->nullable();
            $table->integer('settlement_delay_days')->nullable();
            $table->timestamps();

            $table->index('zoho_customer_id');
            $table->index(['zoho_customer_id', 'settlement_date', 'zoho_invoice_id'], 'ccs_customer_settlement_invoice_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collection_classification_staging');
    }
};
