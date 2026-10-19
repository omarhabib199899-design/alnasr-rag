<?php

namespace App\Http\Controllers;

use App\Services\Zoho\ZohoQueries;
use App\Services\Zoho\ZohoRawTables;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ZOHO Data — copy of the Lovable portal's /reports/zoho-data: a read-only admin monitor of the Zoho sync (summary cards,
 * one row per dataset, a drawer with the column mapping and the latest 20 rows). It has no sync / retry / schedule actions,
 * as theirs; polling only reads.
 *
 * Our engine differs from theirs, so the datasets are ours: every raw Zoho Analytics table we pull (ZohoRawTables, "Table")
 * and every one of Dr. Ahmed's queries we compute on our side (ZohoQueries, "Query Table (computed here)"). Runs and state
 * come from zoho_raw_sync_runs / zoho_raw_sync_state (written by zoho:sync-raw and zoho:compute-queries). Status follows
 * theirs: a dataset is "error" when its latest finished run failed, otherwise "active" once it has succeeded ("new" before).
 * We do not meter Zoho API units, so that card shows the next scheduled refresh instead. Schedules mirror routes/console.php.
 *
 * Access: admins only (theirs: requireEmployee({ admin: true })); here the Configuration permission.
 */
class ZohoDataController extends Controller
{
    private const TZ = 'Asia/Riyadh';

    private const FREQ_HOURLY = 'Hourly (07:00–20:00, not Friday) + 23:00';
    private static function authorize(): void
    {
        abort_unless(auth()->user()?->hasPermission('nav.configuration'), 403);
    }

    /** Every dataset: [key, run table_key, name, type, local table, group, def]. */
    private static function datasets(): array
    {
        $out = [];
        foreach (ZohoRawTables::all() as $key => $def) {
            $out[] = ['key' => $key, 'run_key' => $key, 'name' => $def['name'], 'type' => 'Table', 'table' => ZohoRawTables::table($key),
                'group' => 'hourly', 'raw' => true, 'def' => $def];
        }
        foreach (ZohoQueries::all() as $key => $def) {
            $out[] = ['key' => "query:{$key}", 'run_key' => "query:{$key}", 'name' => $def['zoho_name'], 'type' => 'Query Table (computed here)',
                'table' => ZohoQueries::table($key), 'group' => $def['group'], 'raw' => false, 'def' => $def];
        }

        return $out;
    }

    /** Next start of zoho:refresh for a group (hourly 08–19 Riyadh except Friday + 23:00 nightly; nightly group: 23:00 only). */
    private static function nextRefresh(string $group, Carbon $now): Carbon
    {
        $t = $now->copy()->setTimezone(self::TZ)->startOfHour()->addHour();
        for ($i = 0; $i < 24 * 8; $i++, $t->addHour()) {
            if ($t->hour === 23 || ($group === 'hourly' && $t->hour >= 7 && $t->hour <= 20 && ! $t->isFriday())) {
                return $t;
            }
        }

        return $t;
    }

    /** Next weekly full reload of a raw table (WEEKLY_FULL day at 03:15 Riyadh), or null if it has none. */
    private static function nextFull(string $key, Carbon $now): ?Carbon
    {
        foreach (ZohoRawTables::WEEKLY_FULL as $day => $keys) {
            if (in_array($key, $keys, true)) {
                $t = $now->copy()->setTimezone(self::TZ)->startOfDay()->setTime(3, 15);
                while ($t->dayOfWeek !== $day || $t->lte($now)) {
                    $t->addDay()->setTime(3, 15);
                }

                return $t;
            }
        }

        return null;
    }

    private static function fmt($v): ?string
    {
        return $v ? Carbon::parse($v)->setTimezone(self::TZ)->format('d M Y, H:i') : null;
    }

    private static function weeklyDay(string $key): ?string
    {
        foreach (ZohoRawTables::WEEKLY_FULL as $day => $keys) {
            if (in_array($key, $keys, true)) {
                return [5 => 'Friday', 6 => 'Saturday'][$day] ?? "day {$day}";
            }
        }

        return null;
    }

    public function index()
    {
        self::authorize();

        return view('zoho-data.index');
    }

    /** zoho_sync_admin_overview_v2 + zoho_sync_admin_summary. */
    public function overview(): JsonResponse
    {
        self::authorize();

        return response()->json(Cache::remember('zoho-data:overview', 60, function () {
            $now = now();
            $sets = self::datasets();
            $keys = array_column($sets, 'run_key');
            // Latest run per dataset (by started_at, as theirs) and its last successful full export (source rows).
            $latest = DB::table('zoho_raw_sync_runs as r')->whereIn('r.table_key', $keys)
                ->whereRaw('r.id = (SELECT MAX(r2.id) FROM zoho_raw_sync_runs r2 WHERE r2.table_key = r.table_key)')->get()->keyBy('table_key');
            $lastFinished = DB::table('zoho_raw_sync_runs as r')->whereIn('r.table_key', $keys)->whereNotNull('finished_at')
                ->whereRaw('r.id = (SELECT MAX(r2.id) FROM zoho_raw_sync_runs r2 WHERE r2.table_key = r.table_key AND r2.finished_at IS NOT NULL)')->get()->keyBy('table_key');
            $lastOk = DB::table('zoho_raw_sync_runs')->whereIn('table_key', $keys)->where('status', 'succeeded')
                ->groupBy('table_key')->selectRaw('table_key, MAX(finished_at) at')->pluck('at', 'table_key');
            $lastFull = DB::table('zoho_raw_sync_runs as r')->whereIn('r.table_key', $keys)->where('r.status', 'succeeded')->whereIn('r.kind', ['full', 'file'])
                ->whereRaw("r.id = (SELECT MAX(r2.id) FROM zoho_raw_sync_runs r2 WHERE r2.table_key = r.table_key AND r2.status = 'succeeded' AND r2.kind IN ('full', 'file'))")
                ->pluck('exported_rows', 'table_key');
            $state = DB::table(ZohoRawTables::STATE_TABLE)->get()->keyBy('table_key');

            $rows = [];
            foreach ($sets as $s) {
                $run = $latest[$s['run_key']] ?? null;
                $fin = $lastFinished[$s['run_key']] ?? null;
                $live = $s['raw'] ? ($state[$s['key']]->live_rows ?? null) : (Schema::hasTable($s['table']) ? DB::table($s['table'])->count() : null);
                $next = self::nextRefresh($s['group'], $now);
                $full = $s['raw'] ? self::nextFull($s['key'], $now) : null;
                $day = $s['raw'] ? self::weeklyDay($s['key']) : null;
                $rows[] = [
                    'key' => $s['key'], 'dataset' => $s['name'], 'source_system' => 'Zoho Analytics', 'source_type' => $s['type'],
                    'status' => $fin?->status === 'failed' ? 'error' : (isset($lastOk[$s['run_key']]) ? 'active' : 'new'),
                    'row_count' => $live === null ? null : (int) $live,
                    'source_row_count' => isset($lastFull[$s['run_key']]) ? (int) $lastFull[$s['run_key']] : null,
                    // Theirs: new Date(v).toLocaleString() → e.g. 17/10/2026, 19:02:57.
                    'last_success_at' => ($ok = $lastOk[$s['run_key']] ?? null) ? Carbon::parse($ok)->setTimezone(self::TZ)->format('d/m/Y, H:i:s') : null,
                    'last_run_status' => $run?->status, 'last_run_error' => $run?->error ? mb_substr($run->error, 0, 200) : null,
                    'last_run_duration_seconds' => $run?->finished_at ? (int) round(abs(Carbon::parse($run->started_at)->diffInSeconds(Carbon::parse($run->finished_at)))) : null,
                    'next_sync_at' => self::fmt($full && $full->lt($next) ? $full : $next),
                    'next_sync_kind' => $full && $full->lt($next) ? 'Weekly Full Reload' : ($s['raw'] ? 'Incremental' : 'Recompute'),
                    'mode_label' => $s['raw'] ? 'Incremental'.($day ? " + weekly full ({$day} 03:15)" : '') : 'Computed on our server',
                    'frequency_label' => $s['group'] === 'nightly' ? 'Daily (23:00)' : self::FREQ_HOURLY,
                    'group' => $s['group'],
                ];
            }
            // Theirs: hourly before nightly, Tables before Query Tables, then by name.
            usort($rows, fn ($a, $b) => [$a['group'] === 'nightly', $a['source_type'] !== 'Table', $a['dataset']]
                <=> [$b['group'] === 'nightly', $b['source_type'] !== 'Table', $b['dataset']]);

            $finished = DB::table('zoho_raw_sync_runs')->whereNotNull('finished_at')->max('finished_at');

            return [
                'datasets' => $rows,
                'summary' => [
                    'runs_24h' => DB::table('zoho_raw_sync_runs')->whereIn('table_key', $keys)->where('started_at', '>', $now->copy()->subDay())->count(),
                    'running_now' => DB::table('zoho_raw_sync_runs')->where('status', 'running')->count(),
                    'last_finished_at' => self::fmt($finished),
                    'next_refresh_at' => self::fmt(self::nextRefresh('hourly', $now)),
                ],
            ];
        }));
    }

    /** zoho_sync_admin_dataset — metadata, column mapping and the latest 20 rows of one dataset. */
    public function dataset(): JsonResponse
    {
        self::authorize();
        $key = (string) request()->query('key');
        $s = collect(self::datasets())->firstWhere('key', $key);
        abort_if($s === null, 404);

        $cols = Schema::hasTable($s['table']) ? Schema::getColumns($s['table']) : [];
        $state = $s['raw'] ? DB::table(ZohoRawTables::STATE_TABLE)->where('table_key', $s['key'])->first() : null;
        $ok = DB::table('zoho_raw_sync_runs')->where('table_key', $s['run_key'])->where('status', 'succeeded')->orderByDesc('id')->first();
        $pk = $s['raw'] ? ($s['def']['key'] ?? []) : ['row_no'];
        $lmt = collect($cols)->contains('name', 'last_modified_time') ? 'last_modified_time' : null;

        // Zoho's own column names, from the view metadata the sync caches (raw tables only).
        $headers = [];
        $meta = storage_path("app/zoho-raw-sync/meta/{$s['key']}.json");
        if ($s['raw'] && is_file($meta)) {
            $m = json_decode((string) file_get_contents($meta), true) ?: [];
            $names = array_column($m['columns'] ?? [], 'columnName');
            $headers = array_combine(ZohoRawTables::normalizeHeaders($names), $names) ?: [];
        }
        $mapping = [];
        foreach (array_values(array_filter($cols, fn ($c) => ! in_array($c['name'], ['created_at', 'updated_at'], true))) as $i => $c) {
            $mapping[] = ['position' => $i + 1, 'zoho_column' => $headers[$c['name']] ?? null, 'pg_column' => $c['name'],
                'pg_type' => $c['type'], 'is_pk' => in_array($c['name'], $pk, true)];
        }

        $rows = [];
        if ($cols) {
            try {
                $q = DB::table($s['table'])->limit(20);
                // Theirs: newest by the last-modified column, otherwise by primary key.
                if ($lmt) {
                    $q->orderByRaw("`{$lmt}` IS NULL, `{$lmt}` DESC");
                } else {
                    foreach (array_intersect($pk, array_column($cols, 'name')) as $c) {
                        $q->orderBy($c);
                    }
                }
                $rows = $q->get()->map(fn ($r) => array_map(fn ($v) => is_string($v) ? mb_substr($v, 0, 200) : $v, (array) $r))->all();
            } catch (\Throwable $e) {
                $rows = [];
            }
        }

        return response()->json([
            'dataset' => $s['name'], 'pk_columns' => $pk, 'lmt_column' => $lmt,
            'watermark' => $state?->watermark, 'last_success_kind' => $ok?->kind, 'last_success_finished_at' => self::fmt($ok?->finished_at),
            'next_run_at' => self::fmt(self::nextRefresh($s['group'], now())), 'mapping' => $mapping, 'rows' => $rows,
        ]);
    }
}
