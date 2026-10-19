<?php

namespace App\Console\Commands;

use App\Services\Zoho\ZohoQueries;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Runs Dr. Ahmed's queries (ZohoQueries) over the zoho_raw_* tables and
 * publishes each result as zoho_<key>: CREATE TABLE … AS SELECT into a
 * staging table, sanity check, indexes, then an atomic RENAME swap — a
 * failed or implausibly small result never replaces the live table.
 *
 * CURDATE() in his SQL is Zoho Analytics' date for this Saudi org (org
 * timezone Asia/Riyadh, confirmed in its settings), so each query runs with
 * the session at +03:00 (no DST).
 *
 * Usage: zoho:compute-queries [keys...] [--group=hourly|nightly|all]
 */
class ComputeZohoQueries extends Command
{
    protected $signature = 'zoho:compute-queries
        {queries?* : Query keys from ZohoQueries (run in definition order)}
        {--group=all : hourly | nightly | all}
        {--print : Print the MySQL that would run, without running it}';

    protected $description = 'Run Dr. Ahmed\'s Zoho Analytics queries on our side over zoho_raw_* (staging + atomic swap)';

    /** A result smaller than this share of the current live row count is refused. */
    private const MIN_RATIO = 0.5;

    public function handle(): int
    {
        $requested = $this->argument('queries');
        $keys = array_keys(array_filter(ZohoQueries::all(), fn ($d, $k) => $requested
            ? in_array($k, $requested, true)
            : ($this->option('group') === 'all' || $d['group'] === $this->option('group')), ARRAY_FILTER_USE_BOTH));

        if ($this->option('print')) {
            foreach ($keys as $key) {
                $this->line("-- {$key}\n".ZohoQueries::mysql($key)."\n");
            }

            return self::SUCCESS;
        }

        foreach ($keys as $key) {
            $lock = Cache::lock("zoho-compute:{$key}", 3600);
            if (! $lock->get()) {
                $this->warn("{$key}: already running — skipped (and the queries after it, which may depend on it).");

                return self::FAILURE;
            }

            try {
                $this->compute($key, ZohoQueries::all()[$key]);
            } catch (Throwable $e) {
                $this->error("{$key}: FAILED — ".mb_substr($e->getMessage(), 0, 400));
                report($e);

                // Later queries may read this one's output, so stop rather than build on a stale input.
                return self::FAILURE;
            } finally {
                $lock->release();
            }
        }

        return self::SUCCESS;
    }

    private function compute(string $key, array $def): void
    {
        $table = ZohoQueries::table($key);
        $staging = $table.'_staging_'.now()->format('YmdHis');
        $started = microtime(true);

        $runId = DB::table('zoho_raw_sync_runs')->insertGetId([
            'table_key' => "query:{$key}", 'kind' => 'compute', 'status' => 'running', 'started_at' => now(),
        ]);

        try {
            $sql = ZohoQueries::mysql($key);
            DB::statement("DROP TABLE IF EXISTS `{$staging}`");
            // Riyadh only for the query itself (its CURDATE()); restored right after, because the session time zone
            // also shifts TIMESTAMP columns — it made this command's own run-log times read 3 hours off.
            $tz = DB::selectOne('SELECT @@session.time_zone tz')->tz;
            DB::statement("SET SESSION time_zone = '+03:00'");
            try {
                DB::statement("CREATE TABLE `{$staging}` ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci AS {$sql}");
            } finally {
                DB::statement('SET SESSION time_zone = ?', [$tz]);
            }

            $rows = DB::table($staging)->count();
            $live = Schema::hasTable($table) ? DB::table($table)->count() : 0;
            if ($rows === 0 || ($live > 0 && $rows < $live * self::MIN_RATIO)) {
                throw new \RuntimeException("Result had {$rows} rows vs {$live} live — refusing to replace the live table.");
            }

            // row_no in insertion (= query ORDER BY) order, like the Lovable portal's zoho.* tables.
            $alter = ['ADD COLUMN `row_no` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST'];
            foreach ($def['indexes'] as $i => $cols) {
                $alter[] = "ADD INDEX `idx_{$i}` (`".implode('`,`', $cols).'`)';
            }
            DB::statement("ALTER TABLE `{$staging}` ".implode(', ', $alter));

            if (Schema::hasTable($table)) {
                $old = $table.'_old_'.now()->format('YmdHis');
                DB::statement("RENAME TABLE `{$table}` TO `{$old}`, `{$staging}` TO `{$table}`");
                DB::statement("DROP TABLE `{$old}`");
            } else {
                DB::statement("RENAME TABLE `{$staging}` TO `{$table}`");
            }

            DB::table('zoho_raw_sync_runs')->where('id', $runId)->update([
                'status' => 'succeeded', 'finished_at' => now(), 'written_rows' => $rows,
            ]);
            $this->info(sprintf('%s: %d rows (%.1fs).', $key, $rows, microtime(true) - $started));
        } catch (Throwable $e) {
            DB::statement("DROP TABLE IF EXISTS `{$staging}`");
            DB::table('zoho_raw_sync_runs')->where('id', $runId)->update([
                'status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 5000),
            ]);

            throw $e;
        }
    }
}
