<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-off table creation for customer_payments, run directly via artisan
 * instead of `php artisan migrate` (not used live on this host — schema
 * changes are applied as raw DB::statement() scripts, same as every other
 * table added this engagement). Safe to run more than once — checks
 * Schema::hasTable() first.
 *
 * Replaces ar_ledger_entries (entity_type='customer_payment') as the
 * Payments Received screen's source, per the manager's explicit direction
 * (2026-10-14): stop depending on the Zoho Analytics "AR Ledger Master"
 * view for anything — sync Zoho's real Customer Payments module directly
 * instead. See CustomerPaymentSyncJob for the sync itself.
 */
class CreateCustomerPaymentsTable extends Command
{
    protected $signature = 'reports:create-customer-payments-table';

    protected $description = 'Create the customer_payments table (idempotent, safe to re-run)';

    public function handle(): int
    {
        if (Schema::hasTable('customer_payments')) {
            $this->info('Table customer_payments already exists — nothing to do.');

            return self::SUCCESS;
        }

        DB::statement(<<<'SQL'
            CREATE TABLE `customer_payments` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              `zoho_payment_id` varchar(255) NOT NULL,
              `payment_number` varchar(255) DEFAULT NULL,
              `payment_date` date DEFAULT NULL,
              `zoho_customer_id` varchar(255) DEFAULT NULL,
              `customer_number` varchar(255) DEFAULT NULL,
              `customer_name` varchar(255) DEFAULT NULL,
              `amount` decimal(14,2) DEFAULT NULL,
              `unused_amount` decimal(14,2) DEFAULT NULL,
              `payment_mode` varchar(255) DEFAULT NULL,
              `reference_number` varchar(255) DEFAULT NULL,
              `invoice_numbers` text,
              `zoho_created_time` datetime DEFAULT NULL,
              `zoho_last_modified_time` datetime DEFAULT NULL,
              `extra` longtext,
              `created_at` timestamp NULL DEFAULT NULL,
              `updated_at` timestamp NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `customer_payments_zoho_payment_id_unique` (`zoho_payment_id`),
              KEY `customer_payments_payment_number_index` (`payment_number`),
              KEY `customer_payments_payment_date_index` (`payment_date`),
              KEY `customer_payments_zoho_customer_id_index` (`zoho_customer_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->info('Table customer_payments created.');

        return self::SUCCESS;
    }
}
