<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * cf_request_type / cf_last_approved_snapshot — two more custom fields
 * confirmed live 2026-09-22 already present in live_contact_data for
 * customers with a pending/past edit-approval workflow (261 of 273 synced
 * customers have a snapshot, 6 have a request type — both conditional,
 * only set when an edit request exists). last_approved_snapshot's own value
 * is itself a JSON-formatted string (a point-in-time copy of the contact's
 * fields), stored as TEXT rather than JSON since it isn't guaranteed to
 * always be valid JSON — the view decodes it defensively.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('request_type')->nullable()->after('approval_status');
            $table->text('edit_summary_note')->nullable()->after('request_type');
            $table->text('last_approved_snapshot')->nullable()->after('edit_summary_note');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['request_type', 'edit_summary_note', 'last_approved_snapshot']);
        });
    }
};
