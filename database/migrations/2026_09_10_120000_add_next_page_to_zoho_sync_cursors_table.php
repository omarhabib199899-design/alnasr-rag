<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks where a type's in-progress fetch left off, so a chunked sync job
 * killed mid-run (e.g. a shared host's CPU/time limit) resumes at the right
 * page next tick instead of restarting the whole window from page 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zoho_sync_cursors', function (Blueprint $table) {
            $table->unsignedInteger('next_page')->default(1)->after('last_modified_watermark');
        });
    }

    public function down(): void
    {
        Schema::table('zoho_sync_cursors', function (Blueprint $table) {
            $table->dropColumn('next_page');
        });
    }
};
