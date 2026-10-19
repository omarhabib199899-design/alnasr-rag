<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_recommendations', function (Blueprint $table) {
            $table->unsignedInteger('stockout_count_1yr')->nullable()->after('sold_6mo');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_recommendations', function (Blueprint $table) {
            $table->dropColumn('stockout_count_1yr');
        });
    }
};
