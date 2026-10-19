<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Credit Notes list + details — copy of the Lovable portal's /sales/credit-notes and /sales/credit-notes/$creditNoteId
 * (public.credit_notes_base / credit_notes_page / credit_notes_filter_options / credit_note_detail / credit_note_items_page).
 *
 * Rows are zoho_ar_sales credit notes (entity_type 'creditnote'; creditnote_refund rows are not credit notes, as theirs),
 * assignments from the current Customers record. Warehouse = the city of the credit note's item rows in
 * zoho_sales_item_performance (matched on 'Credit Note' + number + customer + date, as theirs) via zoho_raw_locations:
 * one distinct city → that city, several → 'MULTIPLE CITIES', none → blank. Amounts keep their stored sign (negative).
 * Search, five cascading facets, date range, sort (NULLS LAST, id tie-break) and 50-row pages are server-side, as theirs.
 *
 * The earlier pages (ar_ledger_entries / credit_note_items) stay reachable under /credit-notes/legacy.
 */
class CreditNoteListController extends Controller
{
    private const SORTS = ['date' => 'credit_note_date', 'number' => 'credit_note_number', 'customer' => 'customer_name',
        'amount' => 'amount', 'rep' => 'rep', 'entry' => 'entry', 'owner' => 'owner', 'warehouse' => 'warehouse'];

    private const FACETS = ['rep' => 'rep', 'entry' => 'entry', 'owner' => 'owner', 'management' => 'mgmt', 'warehouse' => 'warehouse'];

    private function base()
    {
        $wh = DB::table('zoho_ar_sales as cn')
            ->join('zoho_sales_item_performance as si', function ($j) {
                $j->on('si.customer_id', '=', 'cn.customer_id')->on('si.transaction_date', '=', 'cn.transaction_date')
                    ->on('si.transaction_number', '=', 'cn.transaction_number')->where('si.transaction_type', 'Credit Note');
            })
            ->leftJoin('zoho_raw_locations as l', 'l.location_id', '=', 'si.location_id')
            ->where('cn.entity_type', 'creditnote')->groupBy('cn.entity_id')
            ->select('cn.entity_id', DB::raw("CASE COUNT(DISTINCT UPPER(NULLIF(TRIM(l.city), ''))) WHEN 0 THEN NULL WHEN 1 THEN MIN(NULLIF(TRIM(l.city), '')) ELSE 'MULTIPLE CITIES' END as warehouse"));

        return DB::table('zoho_ar_sales as a')->leftJoin('zoho_raw_customers as c', 'c.customer_id', '=', 'a.customer_id')
            ->leftJoin('customers as lc', 'lc.zoho_contact_id', '=', 'a.customer_id') // pages link by our own customers.id
            ->leftJoinSub($wh, 'w', 'w.entity_id', '=', 'a.entity_id')
            ->where('a.entity_type', 'creditnote')
            ->select('lc.id as local_customer_id', 'a.entity_id as credit_note_id', DB::raw('DATE(a.transaction_date) as credit_note_date'),
                'a.transaction_number as credit_note_number', 'a.customer_id', 'a.customer_name', 'a.statement_amount as amount', 'w.warehouse',
                DB::raw("NULLIF(TRIM(c.rep_name), '') as rep"), DB::raw("NULLIF(TRIM(c.data_entry), '') as entry"),
                DB::raw("NULLIF(TRIM(c.sales_owner), '') as owner"), DB::raw("NULLIF(TRIM(c.management), '') as mgmt"));
    }

    private static function like(string $s): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($s, 0, 200)).'%';
    }

    /** Filters shared by the page query and the facets; $skip leaves one facet out (its own options stay complete). */
    private function filtered(Request $r, ?string $skip = null)
    {
        $q = DB::query()->fromSub($this->base(), 'b');
        $s = trim((string) $r->query('search', ''));
        if ($s !== '') {
            $q->whereRaw("CONCAT_WS(' ', b.credit_note_number, b.customer_name) LIKE ?", [self::like($s)]);
        }
        foreach (self::FACETS as $key => $col) {
            $vals = array_slice(array_filter((array) $r->query($key, []), 'is_string'), 0, 500);
            if ($vals && $skip !== $key) {
                $q->whereIn("b.{$col}", $vals);
            }
        }
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? $v : null;
        if ($d = $date($r->query('date_from'))) {
            $q->where('b.credit_note_date', '>=', $d);
        }
        if ($d = $date($r->query('date_to'))) {
            $q->where('b.credit_note_date', '<=', $d);
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
        return view('credit-notes.lovable-index');
    }

    public function data(Request $r)
    {
        if ($this->badRange($r)) {
            return response()->json(['error' => 'Invalid date range'], 422);
        }
        $col = 'b.'.(self::SORTS[$r->query('sort')] ?? 'credit_note_date');
        $dir = $r->query('dir') === 'asc' ? 'asc' : 'desc';
        $page = max(0, min(100000, (int) $r->query('page', 0)));

        $f = $this->filtered($r);
        $tot = (clone $f)->selectRaw('COUNT(*) n, COALESCE(SUM(b.amount), 0) amt')->first();
        $rows = (clone $f)->orderByRaw("{$col} IS NULL, {$col} {$dir}, b.credit_note_id {$dir}")->offset($page * 50)->limit(50)->get();

        return response()->json([
            'total' => (int) $tot->n, 'sum_amount' => (float) $tot->amt,
            'total_all' => (int) DB::table('zoho_ar_sales')->where('entity_type', 'creditnote')->count(),
            'rows' => $rows->map(fn ($x) => [
                'id' => (string) $x->credit_note_id, 'date' => $x->credit_note_date ? substr($x->credit_note_date, 0, 10) : null,
                'number' => $x->credit_note_number, 'cust_id' => $x->local_customer_id ? (string) $x->local_customer_id : null,
                'customer' => $x->customer_name, 'amount' => $x->amount === null ? null : (float) $x->amount,
                'rep' => $x->rep, 'entry' => $x->entry, 'owner' => $x->owner, 'warehouse' => $x->warehouse,
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

    public function show(string $creditNote)
    {
        abort_unless(preg_match('/^\d{1,32}$/', $creditNote), 404);
        $cn = $this->detailRow($creditNote);
        abort_if($cn === null, 404);

        return view('credit-notes.lovable-show', ['cn' => $cn, 'back' => request()->query('back')]);
    }

    private function detailRow(string $id)
    {
        return DB::table('zoho_ar_sales as a')->leftJoin('zoho_raw_customers as c', 'c.customer_id', '=', 'a.customer_id')
            ->leftJoin('customers as lc', 'lc.zoho_contact_id', '=', 'a.customer_id')
            ->where('a.entity_type', 'creditnote')->where('a.entity_id', $id)
            ->first(['lc.id as local_customer_id', 'a.entity_id', 'a.transaction_date', 'a.transaction_number', 'a.customer_id',
                DB::raw('COALESCE(c.customer_name, a.customer_name) as customer_name'), 'c.customer_number',
                'a.statement_amount', 'a.balance', 'a.cogs', 'a.balance_aging']);
    }

    public function items(Request $r, string $creditNote)
    {
        abort_unless(preg_match('/^\d{1,32}$/', $creditNote), 404);
        $cn = $this->detailRow($creditNote);
        abort_if($cn === null, 404);
        $cols = ['name' => 'item_name', 'price' => 'item_price', 'qty' => 'qty', 'batch' => 'batch', 'expiry' => 'expiry',
            'discount' => 'discount_pct', 'total' => 'item_total'];
        $sort = $cols[$r->query('sort')] ?? 'item_name';
        $dir = $r->query('dir') === 'desc' ? 'desc' : 'asc';
        $page = max(0, min(10000, (int) $r->query('page', 0)));

        $b = DB::table('zoho_sales_item_performance')->where('customer_id', $cn->customer_id)->where('transaction_date', $cn->transaction_date)
            ->where('transaction_type', 'Credit Note')->where('transaction_number', $cn->transaction_number)
            ->select('row_no', 'item_name', 'item_price', 'qty', 'batch', 'expiry', 'discount_pct', 'item_total');
        $s = trim((string) $r->query('search', ''));
        $f = DB::query()->fromSub($b, 'b');
        if ($s !== '') {
            $f->whereRaw("CONCAT_WS(' ', b.item_name, b.batch) LIKE ?", [self::like($s)]);
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
            ]),
        ]);
    }
}
