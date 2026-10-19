<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-off table creation for customer_classifications, run directly via
 * artisan instead of `php artisan migrate` (not used live on this host —
 * schema changes are applied as raw DB::statement() scripts, same as every
 * other table added this engagement). Safe to run more than once — checks
 * Schema::hasTable() first.
 */
class CreateCustomerClassificationsTable extends Command
{
    protected $signature = 'reports:create-customer-classifications-table';

    protected $description = 'Create the customer_classifications table (idempotent, safe to re-run)';

    public function handle(): int
    {
        if (Schema::hasTable('customer_classifications')) {
            $this->info('Table customer_classifications already exists — nothing to do.');

            return self::SUCCESS;
        }

        DB::statement(<<<'SQL'
            CREATE TABLE `customer_classifications` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              `customer_number` varchar(255) NOT NULL,
              `customer_name` varchar(255) DEFAULT NULL,
              `type` varchar(255) NOT NULL,
              `classification` varchar(255) DEFAULT NULL,
              `data` json NOT NULL,
              `synced_at` timestamp NOT NULL,
              `created_at` timestamp NULL DEFAULT NULL,
              `updated_at` timestamp NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `customer_classifications_customer_number_type_unique` (`customer_number`,`type`),
              KEY `customer_classifications_classification_index` (`classification`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->info('Table customer_classifications created.');

        return self::SUCCESS;
    }
}
