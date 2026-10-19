<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sales Operations — "Sales Operations" sidebar item under Sales Data.
 * Per the manager's exact 18-field spec (2026-10-13 voice note, "Table
 * Invoice"): invoice no, date, value, qty, warehouse, prepared by, review
 * by, delivery by, invoice attached by, exit date/time, delivery
 * date/time, customer check, no of errors after delivery, RASD, data
 * entry, invoice attachment status, carton no, icebox no, pallets no.
 *
 * One row per invoice (invoice_documents). Qty and Warehouse are the only
 * two fields not already on invoice_documents itself — both are per-line
 * attributes, so they're aggregated from invoice_items (summed quantity,
 * distinct warehouse names) joined on document_number = invoice_number.
 * Every other field lives in invoice_documents.extra (confirmed live
 * 2026-10-14 — Zoho's own custom field names: prepared_by, review_by,
 * delivery_by, invoice_attached_by, exit_date_time, delivery_date_time,
 * customer_check, no_of_errors_after_delivery, rasd, data_entry,
 * invoice_attachment_status, carton_no, icebox_no, pallets_no).
 *
 * Real fill rates confirmed live across all 43,381 invoices (not a bug,
 * just how much of this data entry work has actually been done in Zoho):
 * prepared_by/review_by/delivery_by/rasd/exit_date_time are mostly filled
 * (60-86%); data_entry/invoice_attachment_status/carton_no/icebox_no/
 * pallets_no are partially filled (12-31%); invoice_attached_by/
 * delivery_date_time/customer_check/no_of_errors_after_delivery are
 * almost never filled (<1%) — shown as "—" for the many invoices that
 * don't have them, not fabricated.
 */
class SalesOperationsController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->query('search');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        $sortableColumns = ['invoice_number', 'invoice_date', 'total', 'updated_at'];
        $sort = in_array($request->query('sort'), $sortableColumns, true) ? $request->query('sort') : null;
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        $extra = fn (string $key) => "JSON_UNQUOTE(JSON_EXTRACT(extra, '$.{$key}'))";

        $applyFilters = function ($query) use ($search, $dateFrom, $dateTo) {
            if ($search !== null && $search !== '') {
                $query->where('invoice_number', 'like', "%{$search}%");
            }

            if ($dateFrom !== null && $dateFrom !== '') {
                $query->whereDate('invoice_date', '>=', $dateFrom);
            }

            if ($dateTo !== null && $dateTo !== '') {
                $query->whereDate('invoice_date', '<=', $dateTo);
            }

            return $query;
        };

        // No join to invoice_items here on purpose (removed 2026-10-15):
        // qty/warehouse used to come from a leftJoinSub that GROUP BY'd the
        // ENTIRE invoice_items table (1.29M rows) on every single request —
        // confirmed live taking ~18-20s by itself, and this page ran that
        // exact aggregation TWICE (once for the paginated list, once again
        // for the separate totalCount query), none of which any filter here
        // even depends on. invoice_documents alone is filtered/paginated
        // first (fast, existing invoice_date index), then the qty/warehouse
        // aggregate below runs ONLY against this page's ~50 invoice numbers
        // (a small indexed IN-list lookup, not a full-table scan).
        $invoices = $applyFilters(DB::table('invoice_documents'))
            ->select('invoice_number', 'invoice_date', 'total', 'updated_at')
            ->selectRaw($extra('prepared_by').' as prepared_by')
            ->selectRaw($extra('review_by').' as review_by')
            ->selectRaw($extra('delivery_by').' as delivery_by')
            ->selectRaw($extra('invoice_attached_by').' as invoice_attached_by')
            ->selectRaw($extra('exit_date_time').' as exit_date_time')
            ->selectRaw($extra('delivery_date_time').' as delivery_date_time')
            ->selectRaw($extra('customer_check').' as customer_check')
            ->selectRaw($extra('no_of_errors_after_delivery').' as no_of_errors_after_delivery')
            ->selectRaw($extra('rasd').' as rasd')
            ->selectRaw($extra('data_entry').' as data_entry')
            ->selectRaw($extra('invoice_attachment_status').' as invoice_attachment_status')
            ->selectRaw($extra('carton_no').' as carton_no')
            ->selectRaw($extra('icebox_no').' as icebox_no')
            ->selectRaw($extra('pallets_no').' as pallets_no')
            ->when($sort !== null, fn ($query) => $query->orderBy($sort, $direction))
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $invoiceNumbers = $invoices->getCollection()->pluck('invoice_number')->filter()->all();
        $itemAggByNumber = DB::table('invoice_items')
            ->whereNull('zoho_missing_since')
            ->whereIn('document_number', $invoiceNumbers)
            ->select('document_number')
            ->selectRaw('SUM(quantity) as qty')
            ->selectRaw('GROUP_CONCAT(DISTINCT warehouse SEPARATOR ", ") as warehouses')
            ->groupBy('document_number')
            ->get()
            ->keyBy('document_number');

        $invoices->getCollection()->transform(function ($row) use ($itemAggByNumber) {
            $agg = $itemAggByNumber->get($row->invoice_number);
            $row->qty = $agg->qty ?? null;
            $row->warehouses = $agg->warehouses ?? null;

            return $row;
        });

        $lastUpdated = DB::table('invoice_documents')->max('updated_at');

        return view('sales-operations.index', [
            'invoices' => $invoices,
            'search' => $search,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'totalCount' => $invoices->total(),
            'lastUpdated' => $lastUpdated,
        ]);
    }
}
