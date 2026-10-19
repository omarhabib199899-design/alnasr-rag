<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Payments Received — copy of the Lovable portal's /sales/payments-received (public.payments_received_base / _page /
 * _filter_options / _allocations).
 *
 * Rows are zoho_customer_payment (our computed copy of their "Customer Payment" Query Table: one row per payment ×
 * invoice allocation), grouped to one row per payment: number/date/mode/customer = MIN, total and unused = MAX (they
 * repeat on every allocation row, so never summed), applied = SUM, allocation_count = COUNT. Allocations are child rows
 * fetched on expand. Assignments come from the current Customers record. Five cascading facets + payment-date range,
 * sort (NULLS LAST, payment_id tie-break) and 50-row pages are server-side, as theirs. No free-text search, as theirs.
 *
 * The earlier page (customer_payments, synced from Zoho Books) stays reachable at /payments-received/legacy.
 */
class PaymentsReceivedListController extends Controller
{
    private const SORTS = ['date' => 'payment_date', 'number' => 'payment_number', 'customer' => 'customer_name',
        'mode' => 'payment_mode', 'amount' => 'total_payment_amount', 'applied' => 'applied_amount'];

    /** Facet key => column on the base row. The customer facet's value is "name (number)", as their option label. */
    private const FACETS = ['customer' => 'customer_label', 'rep' => 'rep', 'management' => 'mgmt', 'owner' => 'owner', 'entry' => 'entry'];

    private function base()
    {
        $p = DB::table('zoho_customer_payment')->groupBy('payment_id')
            ->select('payment_id', DB::raw('MIN(payment_number) payment_number'), DB::raw('MIN(DATE(payment_date)) payment_date'),
                DB::raw('MIN(payment_mode) payment_mode'), DB::raw('MIN(customer_id) customer_id'),
                DB::raw('MAX(total_payment_amount) total_payment_amount'), DB::raw('MAX(unused_payment_amount) unused_payment_amount'),
                DB::raw('SUM(applied_amount) applied_amount'), DB::raw('COUNT(*) allocation_count'));

        return DB::query()->fromSub($p, 'p')
            ->leftJoin('zoho_raw_customers as c', 'c.customer_id', '=', 'p.customer_id')
            ->leftJoin('customers as lc', 'lc.zoho_contact_id', '=', 'p.customer_id') // pages link by our own customers.id
            ->select('p.*', 'lc.id as local_customer_id', 'c.customer_name', 'c.customer_number',
                DB::raw("CONCAT(COALESCE(c.customer_name, p.customer_id), COALESCE(CONCAT(' (', c.customer_number, ')'), '')) as customer_label"),
                DB::raw("NULLIF(TRIM(c.rep_name), '') as rep"), DB::raw("NULLIF(TRIM(c.data_entry), '') as entry"),
                DB::raw("NULLIF(TRIM(c.sales_owner), '') as owner"), DB::raw("NULLIF(TRIM(c.management), '') as mgmt"));
    }

    /** Filters shared by the page query and the facets; $skip leaves one facet out (its own options stay complete). */
    private function filtered(Request $r, ?string $skip = null)
    {
        $q = DB::query()->fromSub($this->base(), 'b');
        foreach (self::FACETS as $key => $col) {
            $vals = array_slice(array_filter((array) $r->query($key, []), 'is_string'), 0, 500);
            if ($vals && $skip !== $key) {
                $q->whereIn("b.{$col}", $vals);
            }
        }
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? $v : null;
        if ($d = $date($r->query('date_from'))) {
            $q->where('b.payment_date', '>=', $d);
        }
        if ($d = $date($r->query('date_to'))) {
            $q->where('b.payment_date', '<=', $d);
        }

        return $q;
    }

    private function badRange(Request $r): bool
    {
        $from = $r->query('date_from');
        $to = $r->query('date_to');

        return $from && $to && $from > $to;
    }

    public function index()
    {
        return view('payments-received.lovable-index');
    }

    public function data(Request $r)
    {
        if ($this->badRange($r)) {
            return response()->json(['error' => 'Invalid date range'], 422);
        }
        $col = 'b.'.(self::SORTS[$r->query('sort')] ?? 'payment_date');
        $dir = $r->query('dir') === 'asc' ? 'asc' : 'desc';
        $page = max(0, min(100000, (int) $r->query('page', 0)));

        $f = $this->filtered($r);
        $tot = (clone $f)->selectRaw('COUNT(*) n, COALESCE(SUM(b.total_payment_amount), 0) col, COALESCE(SUM(b.applied_amount), 0) app')->first();
        $rows = (clone $f)->orderByRaw("{$col} IS NULL, {$col} {$dir}, b.payment_id {$dir}")->offset($page * 50)->limit(50)->get();
        $n = fn ($v) => $v === null ? null : (float) $v;

        return response()->json([
            'total' => (int) $tot->n, 'sum_collected' => (float) $tot->col, 'sum_applied' => (float) $tot->app,
            'total_all' => (int) DB::table('zoho_customer_payment')->distinct()->count('payment_id'),
            'rows' => $rows->map(fn ($x) => [
                'payment_id' => (string) $x->payment_id, 'payment_number' => $x->payment_number,
                'payment_date' => $x->payment_date ? substr($x->payment_date, 0, 10) : null, 'payment_mode' => $x->payment_mode,
                'cust_id' => $x->local_customer_id ? (string) $x->local_customer_id : null, 'customer_name' => $x->customer_name,
                'customer_number' => $x->customer_number, 'total_payment_amount' => $n($x->total_payment_amount),
                'unused_payment_amount' => $n($x->unused_payment_amount), 'applied_amount' => $n($x->applied_amount),
                'allocation_count' => (int) $x->allocation_count,
            ]),
        ]);
    }

    public function facets(Request $r)
    {
        if ($this->badRange($r)) {
            return response()->json(['error' => 'Invalid date range'], 422);
        }
        $out = [];
        foreach (self::FACETS as $key => $col) {
            $out[$key] = $this->filtered($r, $key)->whereNotNull("b.{$col}")->selectRaw("b.{$col} as value, COUNT(*) as count")
                ->groupBy("b.{$col}")->orderBy("b.{$col}")->get()->map(fn ($x) => ['value' => $x->value, 'count' => (int) $x->count])->all();
        }

        return response()->json($out);
    }

    /** payments_received_allocations — the invoices one payment was applied to (child rows on expand). */
    public function allocations(string $payment)
    {
        abort_unless(preg_match('/^\d{1,32}$/', $payment), 404);
        $n = fn ($v) => $v === null ? null : (float) $v;
        $d = fn ($v) => $v ? substr($v, 0, 10) : null;

        return response()->json(DB::table('zoho_customer_payment')->where('payment_id', $payment)
            ->orderByRaw('invoice_date IS NULL, invoice_date, invoice_number, invoice_payment_id')->get()
            ->map(fn ($x) => [
                'invoice_payment_id' => (string) $x->invoice_payment_id, 'invoice_id' => $x->invoice_id ? (string) $x->invoice_id : null,
                'invoice_number' => $x->invoice_number, 'invoice_date' => $d($x->invoice_date), 'applied_amount' => $n($x->applied_amount),
                'invoice_due_date' => $d($x->invoice_due_date), 'invoice_current_balance' => $n($x->invoice_current_balance),
                'days_to_payment' => $x->days_to_payment, 'days_vs_due_date' => $x->days_vs_due_date,
                'invoice_payment_coverage_pct' => $n($x->invoice_payment_coverage_pct),
            ]));
    }
}
