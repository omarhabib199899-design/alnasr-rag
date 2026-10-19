<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Sales Operations — copy of the Lovable portal's /sales/sales-operations (public.sales_operations_overview /
 * _employee_matrix / _audit_summary / _audit_list over sales_operations_invoices / _periods).
 *
 * Source is zoho_sales_operations (our computed copy of Dr. Ahmed's "Sales Operations" Query Table, one row per invoice
 * per linked sales order), collapsed to one row per invoice_number: MIN() of every field, and `conflict` when an
 * invoice's rows disagree on any invoice-level field (they only ever differ by sales order). Periods are in Asia/Riyadh:
 * lifetime, previous year, YTD, previous month, MTD, yesterday, today.
 *
 *   Overview      — distinct invoices per period, by invoice status / warehouse / e-invoice status / RASD / attachment
 *                   status (blank = its own row), plus carton / icebox / pallet quantity sums.
 *   Employee tabs — data entry / prepared by / review by / delivery by: invoices, items and qty per period per person.
 *   Post-Delivery — last 90 days: attachment not received ('لم تصل'), missing exit time, missing delivery time, with a
 *                   paged invoice list per check.
 *
 * Results are cached for 5 minutes (theirs: React Query staleTime 5 min). The earlier per-invoice table
 * (invoice_documents) stays reachable at /sales-operations/legacy.
 */
class SalesOperationsDashboardController extends Controller
{
    private const PERIODS = ['lifetime', 'prev_year', 'ytd', 'prev_month', 'mtd', 'yesterday', 'today'];

    /** Overview group key => collapsed invoice column. */
    private const GROUPS = ['invoice_status' => 'invoice_status', 'warehouse' => 'warehause', 'e_invoice_status' => 'e_invoice_status',
        'rasd' => 'rasd', 'attachment_status' => 'invoice_attachment_status'];

    /** Employee tab field => collapsed invoice column. */
    private const FIELDS = ['data_entry' => 'invoice_data_entry', 'prepared_by' => 'prepared_by', 'review_by' => 'review_by', 'delivery_by' => 'delivery_by'];

    private const CHECKS = ['attachment_not_received' => "TRIM(COALESCE(invoice_attachment_status, '')) = 'لم تصل'",
        'missing_exit_time' => 'exit_date_time IS NULL', 'missing_delivery_time' => 'delivery_date_time IS NULL'];

    private const TEXT = ['invoice_id', 'customer_id', 'invoice_status', 'warehause', 'e_invoice_status', 'rasd', 'invoice_attachment_status',
        'invoice_data_entry', 'prepared_by', 'review_by', 'delivery_by'];

    private const NUM = ['invoice_item_count', 'invoice_item_qty', 'carton_no', 'icebox_no', 'pallets_no'];

    /** sales_operations_invoices — one row per invoice number, MIN() of each field, conflict flag. */
    private static function invoicesSql(): string
    {
        $cols = array_map(fn ($c) => "MIN(`{$c}`) `{$c}`", array_merge(self::TEXT, ['exit_date_time', 'delivery_date_time'], self::NUM));
        $diff = array_merge(
            array_map(fn ($c) => "COUNT(DISTINCT COALESCE(`{$c}`, '∅')) > 1", array_merge(self::TEXT, ['exit_date_time', 'delivery_date_time'])),
            array_map(fn ($c) => "COUNT(DISTINCT COALESCE(`{$c}`, -1)) > 1", self::NUM),
            ['COUNT(DISTINCT DATE(invoice_date)) > 1']);

        return 'SELECT invoice_number, DATE(MIN(invoice_date)) invoice_date, '.implode(', ', $cols).', COUNT(*) source_rows, '
            .'(COUNT(*) > 1 AND ('.implode(' OR ', $diff).')) `conflict` '
            ."FROM zoho_sales_operations WHERE invoice_number IS NOT NULL AND TRIM(invoice_number) <> '' GROUP BY invoice_number";
    }

    /** sales_operations_periods — date ranges (Asia/Riyadh); lifetime is filled from the data. */
    private static function periods(): array
    {
        $t = Carbon::now('Asia/Riyadh')->startOfDay();
        $d = fn (Carbon $c) => $c->toDateString();

        return ['today' => $d($t), 'prev_year' => [$d($t->copy()->subYear()->startOfYear()), $d($t->copy()->subYear()->endOfYear())],
            'ytd' => [$d($t->copy()->startOfYear()), $d($t)], 'prev_month' => [$d($t->copy()->subMonthNoOverflow()->startOfMonth()), $d($t->copy()->subMonthNoOverflow()->endOfMonth())],
            'mtd' => [$d($t->copy()->startOfMonth()), $d($t)], 'yesterday' => [$d($t->copy()->subDay()), $d($t->copy()->subDay())], 'now' => [$d($t), $d($t)]];
    }

    /** "SUM(CASE WHEN <period> THEN <expr> ELSE 0 END) <prefix>_<period>" for every period, with the bindings in order. */
    private static function perPeriod(string $expr, string $prefix, array $p): array
    {
        $sql = ["SUM({$expr}) {$prefix}_lifetime"];
        $bind = [];
        foreach (['prev_year' => 'prev_year', 'ytd' => 'ytd', 'prev_month' => 'prev_month', 'mtd' => 'mtd', 'yesterday' => 'yesterday', 'today' => 'now'] as $key => $range) {
            $sql[] = "SUM(CASE WHEN invoice_date BETWEEN ? AND ? THEN {$expr} ELSE 0 END) {$prefix}_{$key}";
            array_push($bind, ...$p[$range]);
        }

        return [implode(', ', $sql), $bind];
    }

    private static function pick(object $row, string $prefix, bool $int = true): array
    {
        return array_combine(self::PERIODS, array_map(fn ($k) => $int ? (int) $row->{"{$prefix}_{$k}"} : (float) $row->{"{$prefix}_{$k}"}, self::PERIODS));
    }

    private static function periodRanges(array $p, ?string $min, ?string $max): array
    {
        return ['lifetime' => ['from' => $min, 'to' => $max], 'prev_year' => ['from' => $p['prev_year'][0], 'to' => $p['prev_year'][1]],
            'ytd' => ['from' => $p['ytd'][0], 'to' => $p['ytd'][1]], 'prev_month' => ['from' => $p['prev_month'][0], 'to' => $p['prev_month'][1]],
            'mtd' => ['from' => $p['mtd'][0], 'to' => $p['mtd'][1]], 'yesterday' => ['from' => $p['yesterday'][0], 'to' => $p['yesterday'][1]],
            'today' => ['from' => $p['today'], 'to' => $p['today']]];
    }

    public function index()
    {
        return view('sales-operations.dashboard');
    }

    public function overview(): JsonResponse
    {
        $p = self::periods();

        return response()->json(Cache::remember('sales-ops:overview:'.$p['today'], 300, function () use ($p) {
            $inv = self::invoicesSql();
            [$cnt, $b1] = self::perPeriod('1', 'n', $p);
            $pk = [];
            $bp = [];
            foreach (['carton' => 'carton_no', 'icebox' => 'icebox_no', 'pallets' => 'pallets_no'] as $k => $c) {
                [$s, $b] = self::perPeriod("COALESCE({$c}, 0)", $k, $p);
                $pk[] = $s;
                array_push($bp, ...$b);
            }
            $tot = DB::selectOne("SELECT {$cnt}, ".implode(', ', $pk).', MIN(invoice_date) dmin, MAX(invoice_date) dmax, SUM(`conflict`) conflicts '
                ."FROM ({$inv}) i", array_merge($b1, $bp));

            $groups = [];
            foreach (self::GROUPS as $g => $col) {
                $rows = DB::select("SELECT NULLIF(TRIM({$col}), '') v, {$cnt} FROM ({$inv}) i GROUP BY v ORDER BY v IS NULL, n_lifetime DESC, v", $b1);
                $groups[$g] = array_map(fn ($r) => ['value' => $r->v, 'blank' => $r->v === null, 'metrics' => self::pick($r, 'n')], $rows);
            }

            return [
                'as_of' => $p['today'], 'timezone' => 'Asia/Riyadh', 'periods' => self::periodRanges($p, $tot->dmin, $tot->dmax),
                'totals' => self::pick($tot, 'n'), 'groups' => $groups,
                'packaging' => ['carton' => self::pick($tot, 'carton', false), 'icebox' => self::pick($tot, 'icebox', false), 'pallets' => self::pick($tot, 'pallets', false)],
                'source' => ['conflicting_invoices' => (int) $tot->conflicts],
            ];
        }));
    }

    public function employees(Request $r): JsonResponse
    {
        $field = (string) $r->query('field');
        abort_unless(isset(self::FIELDS[$field]), 404);
        $p = self::periods();

        return response()->json(Cache::remember("sales-ops:employees:{$field}:".$p['today'], 300, function () use ($field, $p) {
            $inv = self::invoicesSql();
            $col = self::FIELDS[$field];
            $sel = [];
            $bind = [];
            foreach (['n' => '1', 'items' => 'COALESCE(invoice_item_count, 0)', 'qty' => 'COALESCE(invoice_item_qty, 0)'] as $k => $e) {
                [$s, $b] = self::perPeriod($e, $k, $p);
                $sel[] = $s;
                array_push($bind, ...$b);
            }
            $sel = implode(', ', $sel);
            $metrics = fn ($row) => array_combine(self::PERIODS, array_map(fn ($k) => ['inv' => (int) $row->{"n_{$k}"},
                'items' => (float) $row->{"items_{$k}"}, 'qty' => (float) $row->{"qty_{$k}"}], self::PERIODS));

            $tot = DB::selectOne("SELECT {$sel}, MIN(invoice_date) dmin, MAX(invoice_date) dmax, SUM(`conflict`) conflicts FROM ({$inv}) i", $bind);
            $rows = DB::select("SELECT NULLIF(TRIM({$col}), '') v, {$sel} FROM ({$inv}) i GROUP BY v ORDER BY v IS NULL, n_lifetime DESC, v", $bind);

            return [
                'field' => $field, 'as_of' => $p['today'], 'periods' => self::periodRanges($p, $tot->dmin, $tot->dmax), 'total' => $metrics($tot),
                'rows' => array_map(fn ($x) => ['value' => $x->v, 'blank' => $x->v === null, 'metrics' => $metrics($x)], $rows),
                'conflicting_invoices' => (int) $tot->conflicts,
            ];
        }));
    }

    /** Last 90 days (today-89 … today, Riyadh) — their audit window. */
    private static function window(): array
    {
        $t = Carbon::now('Asia/Riyadh')->startOfDay();

        return [$t->copy()->subDays(89)->toDateString(), $t->toDateString()];
    }

    public function auditSummary(): JsonResponse
    {
        [$from, $to] = self::window();

        return response()->json(Cache::remember("sales-ops:audit:{$to}", 300, function () use ($from, $to) {
            $c = self::CHECKS;
            $row = DB::selectOne('SELECT COUNT(*) n, '.implode(', ', array_map(fn ($k) => "SUM(CASE WHEN {$c[$k]} THEN 1 ELSE 0 END) `{$k}`", array_keys($c)))
                .', SUM(`conflict`) conflicts FROM ('.self::invoicesSql().') i WHERE invoice_date BETWEEN ? AND ?', [$from, $to]);

            return ['window' => ['from' => $from, 'to' => $to, 'days' => 90], 'invoices_in_window' => (int) $row->n,
                'counts' => array_map(fn ($k) => (int) $row->{$k}, array_combine(array_keys($c), array_keys($c))),
                'conflicting_invoices' => (int) $row->conflicts];
        }));
    }

    public function auditList(Request $r): JsonResponse
    {
        $check = (string) $r->query('check');
        abort_unless(isset(self::CHECKS[$check]), 404);
        $page = max(0, min(100000, (int) $r->query('page', 0)));
        [$from, $to] = self::window();
        $base = '('.self::invoicesSql().') i LEFT JOIN zoho_raw_customers c ON c.customer_id = i.customer_id '
            .'LEFT JOIN customers lc ON lc.zoho_contact_id = i.customer_id '
            .'WHERE i.invoice_date BETWEEN ? AND ? AND '.str_replace(['invoice_attachment_status', 'exit_date_time', 'delivery_date_time'],
                ['i.invoice_attachment_status', 'i.exit_date_time', 'i.delivery_date_time'], self::CHECKS[$check]);

        $total = (int) DB::selectOne("SELECT COUNT(*) n FROM {$base}", [$from, $to])->n;
        // Their order: date, then all-digit numbers numerically, then the rest as text.
        $rows = DB::select("SELECT i.invoice_date, i.invoice_number, i.invoice_id, lc.id local_customer_id, c.customer_name FROM {$base} "
            ."ORDER BY i.invoice_date, i.invoice_number REGEXP '^[0-9]+$' DESC, CASE WHEN i.invoice_number REGEXP '^[0-9]+$' THEN CAST(i.invoice_number AS UNSIGNED) END, i.invoice_number "
            .'LIMIT 50 OFFSET '.($page * 50), [$from, $to]);

        return response()->json(['check' => $check, 'total' => $total, 'rows' => array_map(fn ($x) => [
            'invoice_date' => $x->invoice_date, 'invoice_number' => $x->invoice_number,
            'invoice_id' => $x->invoice_id !== null && preg_match('/^\d+$/', (string) $x->invoice_id) ? (string) $x->invoice_id : null,
            'cust_id' => $x->local_customer_id ? (string) $x->local_customer_id : null, 'customer_name' => $x->customer_name,
        ], $rows)]);
    }
}
