<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw AR ledger transactions (invoices, credit notes, payments, refunds,
 * journals, opening balances), source: AR_Ledger_Master.xlsx (~66.7k rows).
 * Drives three views the manager's Zoho Analytics dashboard shows per
 * customer: aging buckets (balance_aging), currently-outstanding invoices
 * (rows with a non-empty balance_aging), and monthly collections
 * (category = COLLECTION, summed via kpi_amount — the only always-positive
 * amount column; statement_amount is signed negative for collections).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ar_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->date('transaction_date')->nullable();
            $table->string('transaction_number')->nullable();
            $table->string('entity_type', 50)->nullable();
            $table->string('category', 50)->nullable();
            $table->string('zoho_entity_id')->nullable();
            $table->string('zoho_transaction_id')->nullable();
            $table->string('zoho_customer_id');
            $table->string('customer_number')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('ar_account_name')->nullable();
            $table->string('account_type')->nullable();
            $table->string('reference_no')->nullable();
            $table->string('debit_or_credit', 20)->nullable();
            $table->decimal('statement_amount', 14, 2)->nullable();
            $table->decimal('balance', 14, 2)->nullable();
            $table->date('inv_balance_due_date')->nullable();
            $table->decimal('sales_exc_vat', 14, 2)->nullable();
            $table->decimal('cogs', 14, 2)->nullable();
            $table->decimal('gross_profit', 14, 2)->nullable();
            $table->decimal('gp_percent', 10, 2)->nullable();
            $table->decimal('kpi_amount', 14, 2)->nullable();
            $table->string('balance_aging', 50)->nullable();
            $table->timestamps();

            $table->index('zoho_customer_id');
            $table->index('transaction_date');
            $table->index('category');
            $table->index('balance_aging');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ar_ledger_entries');
    }
};
