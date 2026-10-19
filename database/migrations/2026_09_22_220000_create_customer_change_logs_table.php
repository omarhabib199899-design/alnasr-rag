<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-field change history for customers, requested by the manager after
 * asking whether the system can show "how many times has this customer's
 * data changed" the way Zoho's own activity log does — nothing like this
 * existed before (customers.last_approved_snapshot only ever holds the
 * single LATEST pending/approved snapshot, not a running history). Filled by
 * ZohoContactSyncJob comparing each incoming sync against the row's current
 * values before overwriting it — see CustomerChangeLog::TRACKED_FIELDS.
 * Only covers changes detected from 2026-09-22 onward (the day this was
 * built); there's no way to reconstruct history from before this existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_change_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('zoho_contact_id');
            $table->string('field');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->dateTime('changed_at');
            $table->timestamps();

            $table->index(['customer_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_change_logs');
    }
};
