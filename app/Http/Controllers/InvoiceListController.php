<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Invoices list + details — copy of the Lovable portal's /sales/invoices and /sales/invoices/$invoiceId
 * (public.invoices_page / invoices_filter_options / invoice_detail / invoice_items_page).
 *
 * Rows are zoho_ar_sales invoices (our computed copy of their AR SALES), assignments from the current Customers record,
 * item lines from zoho_sales_item_performance matched on customer + date + 'Invoice' + number (as theirs, no item/invoice id).
 * Search, six cascading facets, date ranges, sort (NULLS LAST, id tie-break) and 50-row pages are all server-side, as theirs.
 *
 * Warehouse and the detail's Operations / Sales Orders sections read zoho_sales_operations (our computed copy of their
 * "Sales Operations" Query Table, one row per invoice per linked sales order).
 */
class InvoiceListController extends Controller
{
    private const SORTS = ['date' => 'a.transaction_date', 'number' => 'a.transaction_number', 'customer' => 'a.customer_name',
        'amount' => 'a.statement_amount', 'balance' => 'a.balance', 'due' => 'a.inv_balance_due_date', 'aging' => 'a.balance_aging',
        'warehouse' => 'warehouse', 'rep' => 'rep', 'entry' => 'entry', 'owner' => 'owner', 'gp_pct' => 'a.gp_pct'];

    private const FACETS = ['aging' => 'a.balance_aging', 'warehouse' => 'warehouse', 'rep' => 'rep', 'entry' => 'entry', 'owner' => 'owner', 'management' => 'mgmt'];

    private function base()
    {
        // As their invoices_base: a warehouse only when the invoice has exactly one distinct non-blank value ('MULTIPLE CITIES'
        // counts as one). Grouped per invoice so an invoice with several sales orders stays one row.
        $wh = DB::table('zoho_sales_operations')->groupBy('invoice_id')
            ->select('invoice_id', DB::raw("CASE WHEN COUNT(DISTINCT NULLIF(TRIM(warehause), '')) = 1 THEN MAX(NULLIF(TRIM(warehause), '')) END as warehouse"));

        return DB::table('zoho_ar_sales as a')->leftJoin('zoho_raw_customers as c', 'c.customer_id', '=', 'a.customer_id')
            ->leftJoin('customers as lc', 'lc.zoho_contact_id', '=', 'a.customer_id') // pages link by our own customers.id
            ->leftJoinSub($wh, 'so', 'so.invoice_id', '=', 'a.entity_id')
            ->where('a.entity_type', 'invoice')
            ->select('lc.id as local_customer_id', 'a.entity_id as invoice_id', 'a.transaction_date', 'a.transaction_number', 'a.customer_id', 'a.customer_name',
                'a.statement_amount', 'a.balance', 'a.inv_balance_due_date', 'a.balance_aging', 'a.gp_pct', 'so.warehouse',
                DB::raw("NULLIF(TRIM(c.rep_name), '') as rep"), DB::raw("NULLIF(TRIM(c.data_entry), '') as entry"),
                DB::raw("NULLIF(TRIM(c.sales_owner), '') as owner"), DB::raw("NULLIF(TRIM(c.management), '') as mgmt"));
    }

    /** Filters shared by the page query and the facets; $skip leaves one facet out (its own options stay complete). */
    private function filtered(Request $r, ?string $skip = null)
    {
        $q = DB::query()->fromSub($this->base(), 'a');
        $s = trim((string) $r->query('search', ''));
        if ($s !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($s, 0, 200)).'%';
            $q->whereRaw("CONCAT_WS(' ', a.transaction_number, a.customer_name, a.rep, a.entry, a.owner, a.balance_aging) LIKE ?", [$like]);
        }
        foreach (self::FACETS as $key => $col) {
            $col = $col === 'a.balance_aging' ? 'a.balance_aging' : "a.{$col}";
            $vals = array_slice(array_filter((array) $r->query($key, []), 'is_string'), 0, 500);
            if ($vals && $skip !== $key) {
                $q->whereIn($col, $vals);
            }
        }
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? $v : null;
        foreach ([['date_from', 'a.transaction_date', '>='], ['date_to', 'a.transaction_date', '<='],
            ['due_from', 'a.inv_balance_due_date', '>='], ['due_to', 'a.inv_balance_due_date', '<=']] as [$p, $col, $op]) {
            if ($d = $date($r->query($p))) {
                $q->whereDate($col, $op, $d);
            }
        }

        return $q;
    }

    public function index()
    {
        return view('invoices.lovable-index');
    }

    public function data(Request $r)
    {
        $from = $r->query('date_from');
        $to = $r->query('date_to');
        if ($from && $to && $from > $to || ($r->query('due_from') && $r->query('due_to') && $r->query('due_from') > $r->query('due_to'))) {
            return response()->json(['error' => 'Invalid date range'], 422);
        }
        $sortKey = array_key_exists($r->query('sort'), self::SORTS) ? $r->query('sort') : 'date';
        $col = self::SORTS[$sortKey];
        $col = str_starts_with($col, 'a.') ? $col : "a.{$col}";
        $col = str_replace('a.a.', 'a.', $col);
        $dir = $r->query('dir') === 'asc' ? 'asc' : 'desc';
        $page = max(0, min(100000, (int) $r->query('page', 0)));

        $f = $this->filtered($r);
        $tot = (clone $f)->selectRaw('COUNT(*) n, COALESCE(SUM(a.statement_amount),0) amt, COALESCE(SUM(a.balance),0) bal')->first();
        $rows = (clone $f)->orderByRaw("{$col} IS NULL, {$col} {$dir}, a.invoice_id {$dir}")->offset($page * 50)->limit(50)
            ->get(['a.invoice_id', 'a.transaction_date as d', 'a.transaction_number as n', 'a.local_customer_id', 'a.customer_name as c',
                'a.statement_amount as amt', 'a.balance as bal', 'a.inv_balance_due_date as due', 'a.balance_aging as ag', 'a.warehouse as wh',
                'a.rep', 'a.entry', 'a.owner', 'a.gp_pct']);

        return response()->json([
            'total' => (int) $tot->n, 'sum_amount' => (float) $tot->amt, 'sum_balance' => (float) $tot->bal,
            'total_all' => (int) DB::table('zoho_ar_sales')->where('entity_type', 'invoice')->count(),
            'rows' => $rows->map(fn ($x) => [
                'id' => (string) $x->invoice_id, 'date' => $x->d ? substr($x->d, 0, 10) : null, 'number' => $x->n,
                'cust_id' => $x->local_customer_id ? (string) $x->local_customer_id : null, 'customer' => $x->c,
                'amount' => $x->amt === null ? null : (float) $x->amt, 'balance' => $x->bal === null ? null : (float) $x->bal,
                'due' => $x->due ? substr($x->due, 0, 10) : null, 'aging' => $x->ag, 'warehouse' => $x->wh, 'rep' => $x->rep, 'entry' => $x->entry,
                'owner' => $x->owner, 'gp_pct' => $x->gp_pct === null ? null : (float) $x->gp_pct,
            ]),
        ]);
    }

    public function facets(Request $r)
    {
        $out = [];
        foreach (self::FACETS as $key => $col) {
            $c = $col === 'a.balance_aging' ? $col : "a.{$col}";
            $out[$key] = $this->filtered($r, $key)->whereNotNull($c)->selectRaw("{$c} as value, COUNT(*) as count")->groupBy($c)->orderBy($c)
                ->get()->map(fn ($x) => ['value' => $x->value, 'count' => (int) $x->count])->all();
        }

        return response()->json($out);
    }

    public function show(string $invoice)
    {
        abort_unless(preg_match('/^\d{1,32}$/', $invoice), 404);
        $inv = $this->detailRow($invoice);
        abort_if($inv === null, 404);
        // As their invoice_detail: matched on number + customer; one row per linked sales order, all else identical.
        $opsRows = DB::table('zoho_sales_operations')->where('invoice_number', $inv->transaction_number)
            ->where('customer_id', $inv->customer_id)->orderBy('sales_order_no')->get();
        $ops = $opsRows->isEmpty() ? null : (array) $opsRows->first();

        return view('invoices.lovable-show', [
            'inv' => $inv, 'ops' => $ops, 'opsStatus' => $ops['invoice_status'] ?? null,
            'salesOrders' => $opsRows->pluck('sales_order_no')->filter()->unique()->values(),
            'back' => request()->query('back'),
        ]);
    }

    private function detailRow(string $id)
    {
        return DB::table('zoho_ar_sales as a')->leftJoin('zoho_raw_customers as c', 'c.customer_id', '=', 'a.customer_id')
            ->leftJoin('customers as lc', 'lc.zoho_contact_id', '=', 'a.customer_id')
            ->where('a.entity_type', 'invoice')->where('a.entity_id', $id)
            ->first(['lc.id as local_customer_id', 'a.entity_id', 'a.transaction_date', 'a.transaction_number', 'a.customer_id', DB::raw('COALESCE(c.customer_name, a.customer_name) as customer_name'),
                'c.customer_number', 'a.statement_amount', 'a.balance', 'a.inv_balance_due_date', 'a.cogs', 'a.gross_profit', 'a.gp_pct', 'a.balance_aging']);
    }

    public function items(Request $r, string $invoice)
    {
        abort_unless(preg_match('/^\d{1,32}$/', $invoice), 404);
        $inv = $this->detailRow($invoice);
        abort_if($inv === null, 404);
        $cols = ['name' => 'item_name', 'price' => 'item_price', 'qty' => 'qty', 'batch' => 'batch', 'expiry' => 'expiry', 'discount' => 'discount_pct',
            'total' => 'item_total', 'cogs' => 'cogs', 'gp' => 'item_gross_profit', 'gp_pct' => 'gp_pct'];
        $sort = $cols[$r->query('sort')] ?? 'item_name';
        $dir = $r->query('dir') === 'desc' ? 'desc' : 'asc';
        $page = max(0, min(10000, (int) $r->query('page', 0)));

        $b = DB::table('zoho_sales_item_performance')->where('customer_id', $inv->customer_id)->where('transaction_date', $inv->transaction_date)
            ->where('transaction_type', 'Invoice')->where('transaction_number', $inv->transaction_number)
            ->select('row_no', 'item_name', 'item_price', 'qty', 'batch', 'expiry', 'discount_pct', 'item_total', 'cogs', 'item_gross_profit',
                DB::raw('CASE WHEN item_total IS NULL OR item_total = 0 THEN NULL ELSE item_gross_profit / item_total * 100 END as gp_pct'));
        $s = trim((string) $r->query('search', ''));
        $f = DB::query()->fromSub($b, 'b');
        if ($s !== '') {
            $f->whereRaw("CONCAT_WS(' ', b.item_name, b.batch) LIKE ?", ['%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($s, 0, 200)).'%']);
        }
        $total = (clone $f)->count();
        $rows = $f->orderByRaw("b.{$sort} IS NULL, b.{$sort} {$dir}, b.item_name asc, b.batch is null, b.batch asc, b.expiry is null, b.expiry asc, b.row_no asc")
            ->offset($page * 50)->limit(50)->get();
        $n = fn ($v) => $v === null ? null : (float) $v;

        return response()->json([
            'total' => $total, 'total_all' => DB::query()->fromSub($b, 'b')->count(),
            'rows' => $rows->values()->map(fn ($x, $i) => [
                'rn' => $page * 50 + $i + 1, 'name' => $x->item_name, 'price' => $n($x->item_price), 'qty' => $n($x->qty), 'batch' => $x->batch,
                'expiry' => $x->expiry ? substr($x->expiry, 0, 10) : null, 'discount' => $n($x->discount_pct), 'total' => $n($x->item_total),
                'cogs' => $n($x->cogs), 'gp' => $n($x->item_gross_profit), 'gp_pct' => $n($x->gp_pct),
            ]),
        ]);
    }
}