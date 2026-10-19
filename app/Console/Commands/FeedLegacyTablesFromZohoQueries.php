<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Fills the older tables that existing pages still read (Customers list, Credit Notes, Elnasr Recommendations, AI
 * chat, the /legacy pages) from our own computed copies of the same Zoho Analytics Query Tables — no Zoho API call:
 *
 *   ar_ledger_entries      <- zoho_ar_sales               (was reports:sync-ar-ledger, ~66k rows every hour)
 *   sales_item_performance <- zoho_sales_item_performance (was reports:sync-sales-item-performance, ~800k rows daily)
 *
 * Both sources were verified identical to Zoho's own output (AR SALES 69,994/69,994 rows; SIP 806,131 rows), so the
 * pages keep their exact numbers while the two old full exports (~7,000 API units a day) stop. Runs at the end of
 * every zoho:refresh, right after the queries are recomputed.
 *
 * Same safety as the old imports: build a staging copy, refuse it if it is implausibly small next to the live table,
 * then swap atomically — the live table is never emptied first.
 */
class FeedLegacyTablesFromZohoQueries extends Command
{
    protected $signature = 'zoho:feed-legacy-tables';

    protected $description = 'Refresh ar_ledger_entries and sales_item_performance from zoho_ar_sales / zoho_sales_item_performance (no API call)';

    /** A rebuild smaller than this share of the current live row count is refused. */
    private const MIN_RATIO = 0.9;

    private const FEEDS = [
        'ar_ledger_entries' => <<<'SQL'
            INSERT INTO `%s` (transaction_date, transaction_number, entity_type, category, zoho_entity_id, zoho_transaction_id,
                zoho_customer_id, customer_number, customer_name, ar_account_name, account_type, reference_no, debit_or_credit,
                statement_amount, balance, inv_balance_due_date, sales_exc_vat, cogs, gross_profit, gp_percent, kpi_amount,
                balance_aging, created_at, updated_at)
            SELECT DATE(transaction_date), transaction_number, entity_type, category, entity_id, transaction_id,
                customer_id, customer_number, customer_name, ar_account_name, account_type, reference_no, debit_or_credit,
                statement_amount, balance, DATE(inv_balance_due_date), sales_exc_vat, cogs, gross_profit, gp_pct, kpi_amount,
                balance_aging, ?, ?
            FROM zoho_ar_sales
            WHERE customer_id IS NOT NULL
            SQL,
        'sales_item_performance' => <<<'SQL'
            INSERT INTO `%s` (zoho_transaction_id, transaction_number, transaction_type, transaction_date, zoho_customer_id,
                zoho_item_id, item_name, zoho_location_id, qty, batch, expiry, item_price, discount_amount, cogs, item_total,
                item_gross_profit, discount_percent, created_at, updated_at)
            SELECT transaction_id, transaction_number, transaction_type, DATE(transaction_date), customer_id,
                item_id, item_name, location_id, qty, batch, DATE_FORMAT(expiry, '%%d-%%m-%%Y'), item_price, discount_amount, cogs, item_total,
                item_gross_profit, discount_pct, ?, ?
            FROM zoho_sales_item_performance
            SQL,
    ];

    public function handle(): int
    {
        $failed = 0;
        foreach (self::FEEDS as $table => $sql) {
            try {
                $this->feed($table, $sql);
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$table}: FAILED — ".mb_substr($e->getMessage(), 0, 300).' (live table left untouched)');
                report($e);
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function feed(string $table, string $sql): void
    {
        $started = microtime(true);
        $staging = $table.'_staging_'.now()->format('YmdHis').'_'.random_int(1000, 9999);
        DB::statement("CREATE TABLE `{$staging}` LIKE `{$table}`");

        try {
            $now = now();
            DB::statement(sprintf($sql, $staging), [$now, $now]);

            $rows = DB::table($staging)->count();
            $live = DB::table($table)->count();
            if ($rows === 0 || $rows < $live * self::MIN_RATIO) {
                throw new \RuntimeException("rebuilt {$rows} rows vs {$live} live — refusing to replace the live table.");
            }

            $old = $table.'_old_'.now()->format('YmdHis').'_'.random_int(1000, 9999);
            DB::statement("RENAME TABLE `{$table}` TO `{$old}`, `{$staging}` TO `{$table}`");
            DB::statement("DROP TABLE `{$old}`");
        } catch (Throwable $e) {
            DB::statement("DROP TABLE IF EXISTS `{$staging}`");

            throw $e;
        }

        $this->info(sprintf('%s: %d rows (was %d) in %.1fs.', $table, $rows, $live, microtime(true) - $started));
    }
}
