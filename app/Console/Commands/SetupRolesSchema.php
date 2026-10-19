<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-off schema setup for the User Management feature (roles table +
 * role_id/phone/username on users), run directly via artisan instead of
 * `php artisan migrate` (not used live on this host). Idempotent — safe to
 * re-run, checks Schema::hasTable/hasColumn before each change.
 */
class SetupRolesSchema extends Command
{
    protected $signature = 'users:setup-roles-schema';

    protected $description = 'Create the roles table and add role_id/phone/username to users (idempotent, safe to re-run)';

    public function handle(): int
    {
        if (! Schema::hasTable('roles')) {
            DB::statement(<<<'SQL'
                CREATE TABLE `roles` (
                  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                  `name` varchar(255) NOT NULL,
                  `permissions` json NOT NULL,
                  `created_at` timestamp NULL DEFAULT NULL,
                  `updated_at` timestamp NULL DEFAULT NULL,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `roles_name_unique` (`name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
            $this->info('Created roles table.');
        } else {
            $this->info('roles table already exists — skipped.');
        }

        if (! Schema::hasColumn('users', 'role_id')) {
            DB::statement('ALTER TABLE `users` ADD COLUMN `role_id` bigint unsigned NULL AFTER `id`');
            DB::statement('ALTER TABLE `users` ADD CONSTRAINT `users_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL');
            $this->info('Added users.role_id.');
        } else {
            $this->info('users.role_id already exists — skipped.');
        }

        if (! Schema::hasColumn('users', 'phone')) {
            DB::statement('ALTER TABLE `users` ADD COLUMN `phone` varchar(255) NULL AFTER `email`');
            $this->info('Added users.phone.');
        } else {
            $this->info('users.phone already exists — skipped.');
        }

        if (! Schema::hasColumn('users', 'username')) {
            DB::statement('ALTER TABLE `users` ADD COLUMN `username` varchar(255) NULL AFTER `phone`');
            DB::statement('ALTER TABLE `users` ADD UNIQUE KEY `users_username_unique` (`username`)');
            $this->info('Added users.username.');
        } else {
            $this->info('users.username already exists — skipped.');
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
