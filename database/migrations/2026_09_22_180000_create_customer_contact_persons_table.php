<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Zoho contact person (a named individual at a customer, e.g.
 * their purchasing contact's email) — sourced from the org's Zoho Analytics
 * "Customer Contact Persons" table, confirmed live 2026-09-22: 7 columns,
 * 401 rows, most rows have only email populated (first/last name blank for
 * this org in practice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_contact_persons', function (Blueprint $table) {
            $table->id();
            $table->string('zoho_contact_person_id')->unique();
            $table->string('zoho_customer_id')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            // dateTime, not timestamp — see invoice_documents migration for
            // why (a TIMESTAMP column rejects otherwise-valid values that
            // fall in this host's MySQL session timezone's DST gap).
            $table->dateTime('zoho_created_time')->nullable();
            $table->dateTime('zoho_last_modified_time')->nullable();
            $table->timestamps();

            $table->index('zoho_customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_contact_persons');
    }
};
