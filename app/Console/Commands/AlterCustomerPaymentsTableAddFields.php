<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the remaining columns from Zoho's Customer Payments export/API that
 * the original customer_payments table left out — per explicit user
 * request (2026-10-14): "why u did not use all the columns in the excel
 * sheet". Idempotent (checks Schema::hasColumn() per column, safe to
 * re-run) — same pattern as every other schema change this engagement,
 * since `php artisan migrate` isn't used live on this host.
 */
class AlterCustomerPaymentsTableAddFields extends Command
{
    protected $signature = 'reports:alter-customer-payments-add-fields';

    protected $description = 'Add the remaining Zoho Customer Payments columns to customer_payments (idempotent)';

    private const COLUMNS = [
        'txn_posting_date' => 'date DEFAULT NULL',
        'bcy_amount' => 'decimal(14,2) DEFAULT NULL',
        'bcy_unused_amount' => 'decimal(14,2) DEFAULT NULL',
        'account_id' => 'varchar(255) DEFAULT NULL',
        'account_name' => 'varchar(255) DEFAULT NULL',
        'description' => 'text',
        'last_four_digits' => 'varchar(50) DEFAULT NULL',
        'gateway_transaction_id' => 'varchar(255) DEFAULT NULL',
        'bcy_refunded_amount' => 'decimal(14,2) DEFAULT NULL',
        'has_attachment' => 'tinyint(1) DEFAULT NULL',
        'documents' => 'text',
        'tax_account_id' => 'varchar(255) DEFAULT NULL',
        'tax_account_name' => 'varchar(255) DEFAULT NULL',
        'tax_amount_withheld' => 'decimal(14,2) DEFAULT NULL',
        'currency_code' => 'varchar(10) DEFAULT NULL',
        'payment_method' => 'varchar(255) DEFAULT NULL',
        'location_name' => 'varchar(255) DEFAULT NULL',
    ];

    public function handle(): int
    {
        if (! Schema::hasTable('customer_payments')) {
            $this->error('Table customer_payments does not exist yet — run reports:create-customer-payments-table first.');

            return self::FAILURE;
        }

        foreach (self::COLUMNS as $column => $definition) {
            if (Schema::hasColumn('customer_payments', $column)) {
                continue;
            }
            DB::statement("ALTER TABLE `customer_payments` ADD COLUMN `{$column}` {$definition}");
            $this->info("Added column: {$column}");
        }

        if (! Schema::hasColumn('customer_payments', 'account_name')) {
            // unreachable in practice (just added above), guards against a
            // partial failure between the loop and this index add
            return self::SUCCESS;
        }

        try {
            DB::statement('ALTER TABLE `customer_payments` ADD INDEX `customer_payments_account_name_index` (`account_name`)');
        } catch (\Throwable $e) {
            // index already exists on a re-run — fine, nothing to do
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
