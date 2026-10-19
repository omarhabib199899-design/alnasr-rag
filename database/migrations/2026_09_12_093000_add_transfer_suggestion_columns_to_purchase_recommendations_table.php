<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provisional answer to the manager's "overstocked in a branch because of a
 * transfer, suggest a trial transfer" ask — see PurchaseRecommendationBuilder
 * ::suggestTransfer() for the inferred logic, not yet confirmed against what
 * he actually meant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_recommendations', function (Blueprint $table) {
            $table->string('transfer_suggested_from')->nullable()->after('warehouse_count');
            $table->string('transfer_suggested_to')->nullable()->after('transfer_suggested_from');
            $table->integer('transfer_suggested_qty')->nullable()->after('transfer_suggested_to');
            $table->decimal('estimated_weekly_loss_if_no_transfer', 12, 2)->nullable()->after('transfer_suggested_qty');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_recommendations', function (Blueprint $table) {
            $table->dropColumn([
                'transfer_suggested_from',
                'transfer_suggested_to',
                'transfer_suggested_qty',
                'estimated_weekly_loss_if_no_transfer',
            ]);
        });
    }
};
