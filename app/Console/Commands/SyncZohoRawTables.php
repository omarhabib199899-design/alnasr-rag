<?php

namespace App\Console\Commands;

use App\Models\ZohoConnection;
use App\Services\Zoho\ZohoAnalyticsClient;
use App\Services\Zoho\ZohoRawTables;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Copies the raw Zoho Analytics Tables listed in ZohoRawTables into
 * zoho_raw_<key>, as the input for Dr. Ahmed's queries (see
 * database/zoho-analytics-queries/). Never deletes live data first:
 *
 * - full: export → load into a fresh staging table → sanity check → atomic
 *   RENAME swap. A failed or implausibly small export leaves the live table
 *   untouched.
 * - incremental: export only rows with "Last Modified Time" at/after the
 *   watermark (minus an overlap) → upsert on the table's unique key. Falls
 *   back to full when the table doesn't exist yet, has no watermark, or the
 *   view's columns changed in Zoho.
 *
 * --file: load one table from a CSV exported by hand from the Zoho Analytics
 * UI (Export → CSV, all rows and columns) instead of the Bulk API — UI
 * exports don't consume API units (added 2026-10-19 after the first API
 * full loads exhausted the account's daily quota). Always a full staging+swap
 * load; the given file is never deleted. Column definitions come from the
 * view metadata cached by the last successful API run (or the API if it
 * answers). --as-of records when the file was exported, which the DSO cutoff
 * reads from the run log.
 *
 * Usage: zoho:sync-raw [keys...] [--group=all|full|incremental] [--full]
 *        zoho:sync-raw accrual_transactions --file=/path/export.csv [--as-of="2026-10-19 09:30"]
 */
class SyncZohoRawTables extends Command
{
    protected $signature = 'zoho:sync-raw
        {tables?* : Table keys from ZohoRawTables (default: --group)}
        {--group=all : all | full | incremental}
        {--full : Force a full reload (staging + swap) even for incremental tables}
        {--no-full : Never fall back to a full export (scheduled runs): such a table is skipped and reported}
        {--file= : Load a hand-exported CSV for the single given table instead of calling the API}
        {--as-of= : With --file: when the file was exported (Asia/Riyadh, Y-m-d H:i); defaults to the file time}';

    protected $description = 'Copy the raw Zoho Analytics tables Dr. Ahmed\'s queries read into zoho_raw_* (safe staging+swap / upsert)';

    private const POLL_DELAY_SECONDS = 10;

    /** Up to an hour for Zoho to prepare an export — the weekly full of accrual_transactions is ~2.4M rows. */
    private const MAX_POLL_ATTEMPTS = 360;

    /** A full reload smaller than this share of the current live row count is refused. */
    private const MIN_FULL_RATIO = 0.9;

    public function handle(ZohoAnalyticsClient $client): int
    {
        $connection = ZohoConnection::where('status', 'connected')->latest('updated_at')->first();
        if ($connection === null) {
            $this->error('No connected Zoho connection found.');

            return self::FAILURE;
        }

        $keys = $this->argument('tables') ?: array_keys(array_filter(
            ZohoRawTables::all(),
            fn ($d) => $this->option('group') === 'all' || $d['mode'] === $this->option('group'),
        ));

        if ($this->option('file') !== null) {
            if (count($keys) !== 1 || ! $this->argument('tables')) {
                $this->error('--file loads exactly one table: name it, e.g. zoho:sync-raw accrual_transactions --file=...');

                return self::FAILURE;
            }
            if (! is_file($this->option('file'))) {
                $this->error('File not found: '.$this->option('file'));

                return self::FAILURE;
            }
        }

        $failed = 0;
        foreach ($keys as $key) {
            $def = ZohoRawTables::get($key);
            $lock = Cache::lock("zoho-sync-raw:{$key}", 3 * 3600);
            if (! $lock->get()) {
                $this->warn("{$key}: another run is in progress — skipped.");

                continue;
            }

            try {
                $this->syncTable($client, $connection, $def);
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$key}: FAILED — ".mb_substr($e->getMessage(), 0, 300));
                report($e);
            } finally {
                $lock->release();
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function syncTable(ZohoAnalyticsClient $client, ZohoConnection $connection, array $def): void
    {
        $key = $def['key_name'];
        $table = $def['table'];
        $started = microtime(true);

        $file = $this->option('file');
        $meta = $this->viewMeta($client, $connection, $def, $file !== null);
        $columns = $this->columnsFromMeta($meta, $key);

        $state = DB::table(ZohoRawTables::STATE_TABLE)->where('table_key', $key)->first();
        $schemaChanged = Schema::hasTable($table) && Schema::getColumnListing($table) !== array_keys($columns);

        $full = $file !== null || $this->option('full') || $def['mode'] === 'full' || ! Schema::hasTable($table)
            || $state?->watermark === null || $schemaChanged || ! isset($columns['last_modified_time']);
        $kind = $full ? 'full' : 'incremental';

        // Scheduled runs never fall back to a full export on their own (a 2M-row table would eat the day's API units):
        // the table is left as it is and the reason logged, for a person to decide.
        if ($full && $this->option('no-full')) {
            $why = $schemaChanged ? 'the view\'s columns changed in Zoho' : (! Schema::hasTable($table) ? 'the table does not exist yet'
                : ($state?->watermark === null ? 'there is no watermark yet' : 'it needs a full reload'));
            throw new \RuntimeException("full reload needed ({$why}) — skipped because of --no-full; run zoho:sync-raw {$key} --full manually");
        }

        // For a hand-exported file, the run "started" when Zoho produced the export, not when we load it.
        $startedAt = now();
        if ($file !== null) {
            $startedAt = $this->option('as-of')
                ? Carbon::parse($this->option('as-of'), 'Asia/Riyadh')->setTimezone(config('app.timezone'))
                : Carbon::createFromTimestamp(filemtime($file))->setTimezone(config('app.timezone'));
        }

        $runId = DB::table('zoho_raw_sync_runs')->insertGetId([
            'table_key' => $key, 'kind' => $file !== null ? 'file' : $kind, 'status' => 'running', 'started_at' => $startedAt,
        ]);

        $csvPath = null;
        try {
            if ($file !== null) {
                $this->line("{$key}: loading hand-exported file {$file}…");
            } else {
                $config = ['responseFormat' => 'csv'];
                if (! $full) {
                    $since = Carbon::parse($state->watermark)->subMinutes(ZohoRawTables::OVERLAP_MINUTES)->format('Y-m-d H:i:s');
                    $config['criteria'] = "\"Last Modified Time\" >= '{$since}'";
                }

                $this->line("{$key}: {$kind} export…");
                $csvPath = $this->export($client, $connection, $def['view_id'], $config, $key);
            }

            [$exported, $written, $watermark] = $full
                ? $this->loadFull($file ?? $csvPath, $def, $columns)
                : $this->loadIncremental($csvPath, $def, $columns);

            $liveRows = DB::table($table)->count();
            DB::table(ZohoRawTables::STATE_TABLE)->updateOrInsert(['table_key' => $key], array_filter([
                'last_full_at' => $full ? now() : null,
                'last_incremental_at' => $full ? null : now(),
                'watermark' => $watermark ?? $state?->watermark,
                'live_rows' => $liveRows,
                'updated_at' => now(),
            ], fn ($v) => $v !== null) + ['created_at' => $state?->created_at ?? now()]);

            DB::table('zoho_raw_sync_runs')->where('id', $runId)->update([
                'status' => 'succeeded', 'finished_at' => now(), 'exported_rows' => $exported, 'written_rows' => $written,
            ]);

            $this->info(sprintf('%s: %s done — %d exported, %d written, %d live rows (%.1fs).',
                $key, $kind, $exported, $written, $liveRows, microtime(true) - $started));
        } catch (Throwable $e) {
            DB::table('zoho_raw_sync_runs')->where('id', $runId)->update([
                'status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 5000),
            ]);

            throw $e;
        } finally {
            if ($csvPath !== null) {
                @unlink($csvPath);
            }
        }
    }

    /**
     * The view's column metadata. Cached on disk after every successful API
     * read so a --file load works without spending API units (and while the
     * daily quota is exhausted).
     */
    private function viewMeta(ZohoAnalyticsClient $client, ZohoConnection $connection, array $def, bool $allowCache): array
    {
        $cache = storage_path("app/zoho-raw-sync/meta/{$def['key_name']}.json");
        try {
            $meta = $client->getViewDetails($connection, $def['view_id']);
            if (! is_dir(dirname($cache))) {
                mkdir(dirname($cache), 0755, true);
            }
            file_put_contents($cache, json_encode($meta, JSON_UNESCAPED_UNICODE));

            return $meta;
        } catch (Throwable $e) {
            if ($allowCache && is_file($cache)) {
                $this->warn("{$def['key_name']}: view metadata unavailable from the API ({$e->getMessage()}) — using the cached copy.");

                return json_decode(file_get_contents($cache), true);
            }

            throw $e;
        }
    }

    /** @return array<string, array{header: string, type: string}> normalized column => source header + MySQL type */
    private function columnsFromMeta(array $meta, string $key): array
    {
        $count = count($meta['columns'] ?? []);
        if ($count === 0) {
            throw new \RuntimeException('View metadata returned no columns.');
        }

        $names = ZohoRawTables::normalizeHeaders(array_column($meta['columns'], 'columnName'));
        $columns = [];
        foreach ($meta['columns'] as $i => $col) {
            $name = $names[$i];
            $columns[$name] = [
                'header' => $col['columnName'],
                'type' => ZohoRawTables::mysqlType($name, (string) ($col['dataTypeName'] ?? ''), $key),
            ];
        }

        return $columns;
    }

    private function export(ZohoAnalyticsClient $client, ZohoConnection $connection, string $viewId, array $config, string $key): string
    {
        $workspaceId = (string) config('zoho.analytics.workspace_id');
        $jobId = $client->createExportJob($connection, $workspaceId, $viewId, $config);

        for ($attempt = 1; $attempt <= self::MAX_POLL_ATTEMPTS; $attempt++) {
            sleep(self::POLL_DELAY_SECONDS);
            $status = $client->getExportJobStatus($connection, $workspaceId, $jobId)['jobStatus'];
            if ($status === 'JOB COMPLETED') {
                $dir = storage_path('app/zoho-raw-sync');
                if (! is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $path = "{$dir}/{$key}_".now()->format('Ymd_His').'.csv';
                $client->downloadExportDataToFile($connection, $workspaceId, $jobId, $path);

                return $path;
            }
            if (in_array($status, ['ERROR OCCURRED', 'JOB NOT FOUND'], true)) {
                throw new \RuntimeException("Export job {$jobId} ended with status '{$status}'.");
            }
        }

        throw new \RuntimeException("Export job {$jobId} never completed.");
    }

    /** @return array{0: int, 1: int, 2: ?string} exported rows, written rows, new watermark */
    private function loadFull(string $csvPath, array $def, array $columns): array
    {
        $table = $def['table'];
        $staging = $table.'_staging_'.now()->format('YmdHis');

        DB::statement("DROP TABLE IF EXISTS `{$staging}`");
        DB::statement($this->createTableSql($staging, $columns));

        try {
            [$exported, $watermark] = $this->streamRows($csvPath, $columns, function (array $batch) use ($staging) {
                DB::table($staging)->insert($batch);
            });

            $live = Schema::hasTable($table) ? DB::table($table)->count() : 0;
            if ($exported === 0 || ($live > 0 && $exported < $live * self::MIN_FULL_RATIO)) {
                throw new \RuntimeException("Full export had {$exported} rows vs {$live} live — refusing to replace the live table.");
            }

            $this->addIndexes($staging, $def);

            if (Schema::hasTable($table)) {
                $old = $table.'_old_'.now()->format('YmdHis');
                DB::statement("RENAME TABLE `{$table}` TO `{$old}`, `{$staging}` TO `{$table}`");
                DB::statement("DROP TABLE `{$old}`");
            } else {
                DB::statement("RENAME TABLE `{$staging}` TO `{$table}`");
            }
        } catch (Throwable $e) {
            DB::statement("DROP TABLE IF EXISTS `{$staging}`");

            throw $e;
        }

        return [$exported, $exported, $watermark];
    }

    /** @return array{0: int, 1: int, 2: ?string} */
    private function loadIncremental(string $csvPath, array $def, array $columns): array
    {
        $table = $def['table'];
        $update = array_values(array_diff(array_keys($columns), $def['key']));
        $written = 0;

        [$exported, $watermark] = $this->streamRows($csvPath, $columns, function (array $batch) use ($table, $def, $update, &$written) {
            $written += DB::table($table)->upsert($batch, $def['key'], $update);
        });

        return [$exported, $written, $watermark];
    }

    /**
     * Reads the CSV (fgetcsv, so embedded newlines are safe), maps each header
     * to its normalized column, casts by type, and hands batches to $sink.
     *
     * @return array{0: int, 1: ?string} row count, max last_modified_time
     */
    private function streamRows(string $csvPath, array $columns, callable $sink): array
    {
        $handle = fopen($csvPath, 'r');
        try {
            $header = fgetcsv($handle);
            if ($header === false) {
                return [0, null];
            }
            $header[0] = preg_replace('/^\x{FEFF}/u', '', (string) $header[0]);

            $index = array_flip(ZohoRawTables::normalizeHeaders(array_map('strval', $header)));
            $missing = array_diff(array_keys($columns), array_keys($index));
            if ($missing) {
                throw new \RuntimeException('Export is missing columns: '.implode(', ', $missing));
            }

            $batchSize = max(100, intdiv(60000, count($columns)));
            $batch = [];
            $count = 0;
            $watermark = null;

            while (($row = fgetcsv($handle)) !== false) {
                if ($row === [null]) {
                    continue;
                }
                $record = [];
                foreach ($columns as $name => $col) {
                    $record[$name] = self::cast($row[$index[$name]] ?? null, $col['type']);
                }
                if (isset($record['last_modified_time']) && ($watermark === null || $record['last_modified_time'] > $watermark)) {
                    $watermark = $record['last_modified_time'];
                }
                $batch[] = $record;
                $count++;

                if (count($batch) >= $batchSize) {
                    $sink($batch);
                    $batch = [];
                }
            }
            if ($batch) {
                $sink($batch);
            }

            return [$count, $watermark];
        } finally {
            fclose($handle);
        }
    }

    private function createTableSql(string $table, array $columns): string
    {
        $defs = [];
        foreach ($columns as $name => $col) {
            $defs[] = "`{$name}` {$col['type']}";
        }

        return "CREATE TABLE `{$table}` (".implode(', ', $defs).') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC';
    }

    private function addIndexes(string $table, array $def): void
    {
        $parts = [];
        // Unique only where upserts depend on it (incremental); full-reload tables just get a plain index on the id,
        // so an unexpected duplicate in Zoho's data never blocks the load.
        if ($def['key']) {
            $kind = $def['mode'] === 'incremental' ? 'UNIQUE KEY' : 'INDEX';
            $parts[] = "ADD {$kind} `key_idx` (`".implode('`,`', $def['key']).'`)';
        }
        foreach (ZohoRawTables::INDEXES[$def['key_name']] ?? [] as $i => $cols) {
            $parts[] = "ADD INDEX `idx_{$i}` (`".implode('`,`', $cols).'`)';
        }
        if ($parts) {
            DB::statement("ALTER TABLE `{$table}` ".implode(', ', $parts));
        }
    }

    private static function cast(mixed $raw, string $type): mixed
    {
        $raw = is_string($raw) ? trim($raw) : $raw;
        if ($raw === null || $raw === '') {
            return null;
        }

        if (str_starts_with($type, 'BIGINT')) {
            $s = str_replace(',', '', (string) $raw);
            if (is_numeric($s) && stripos($s, 'e') !== false) {
                return sprintf('%.0f', (float) $s);
            }

            // Kept as a string, never via float — Zoho IDs are 16–19 digits and would lose precision.
            return preg_match('/^(-?\d+)(\.0+)?$/', $s, $m) ? $m[1] : null;
        }
        if (str_starts_with($type, 'DECIMAL')) {
            $s = preg_replace('/[^0-9.\-eE]/', '', (string) $raw);

            return is_numeric($s) ? $s : null;
        }
        if (str_starts_with($type, 'DATETIME')) {
            return self::parseDate((string) $raw);
        }
        if (str_starts_with($type, 'TINYINT')) {
            return in_array(mb_strtolower((string) $raw), ['true', 'yes', '1'], true) ? 1 : 0;
        }

        return (string) $raw;
    }

    /** Zoho Analytics exports this workspace's dates as dd/MM/yyyy[ HH:mm:ss]. */
    private static function parseDate(string $value): ?string
    {
        // Explicit day-first formats only — Carbon::parse below would read 05/10 as May 10 (US order).
        foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y h:i:s A', 'd/m/Y h:i A', 'd/m/Y', 'Y-m-d H:i:s', 'Y-m-d',
            'd-m-Y H:i:s', 'd-m-Y', 'd M Y H:i:s', 'd M, Y H:i:s', 'd M Y h:i A', 'd M, Y h:i:s A', 'd M, Y h:i A', 'd M Y', 'd M, Y'] as $format) {
            $d = \DateTime::createFromFormat('!'.$format, $value);
            $errors = \DateTime::getLastErrors();
            if ($d !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $d->format('Y-m-d H:i:s');
            }
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }
}
