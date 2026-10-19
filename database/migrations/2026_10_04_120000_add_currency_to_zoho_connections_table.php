<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zoho_connections', function (Blueprint $table) {
            $table->string('currency_code', 10)->nullable()->after('zoho_organization_id');
            $table->string('currency_symbol', 10)->nullable()->after('currency_code');
        });
    }

    public function down(): void
    {
        Schema::table('zoho_connections', function (Blueprint $table) {
            $table->dropColumn(['currency_code', 'currency_symbol']);
        });
    }
};
