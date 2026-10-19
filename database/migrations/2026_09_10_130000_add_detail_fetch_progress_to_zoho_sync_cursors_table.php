<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zoho's list endpoints (e.g. GET /purchaseorders) don't include line_items
 * — only the per-document detail endpoint (GET /purchaseorders/{id}) does.
 * So syncing now means one detail fetch per document, not per page. These
 * columns track exactly which document within the current list page we've
 * gotten to, so a chunked run resumes mid-page instead of re-fetching
 * detail for documents already persisted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zoho_sync_cursors', function (Blueprint $table) {
            $table->unsignedInteger('next_index')->default(0)->after('next_page');
            $table->timestamp('pending_watermark')->nullable()->after('last_modified_watermark');
        });
    }

    public function down(): void
    {
        Schema::table('zoho_sync_cursors', function (Blueprint $table) {
            $table->dropColumn(['next_index', 'pending_watermark']);
        });
    }
};
