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
        Schema::create('customer_classifications', function (Blueprint $table) {
            $table->id();
            $table->string('customer_number');
            $table->string('customer_name')->nullable();
            $table->string('type'); // sales, collection, profitability, documents
            $table->string('classification')->nullable(); // the final label, e.g. "Strategic - Growing"
            $table->json('data'); // full raw row from the Analytics export, for the fields not promoted to columns
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['customer_number', 'type']);
            $table->index('classification');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_classifications');
    }
};
