<?php

namespace App\Http\Controllers;

use App\Models\Import;
use App\Models\InventoryWarehouseStock;
use App\Models\InvoiceDocument;
use App\Models\InvoiceItem;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRecommendation;
use App\Models\ReturnItem;
use App\Models\SaleItem;
use App\Models\SfdaPriceCheck;
use App\Services\Zoho\ZohoTransactionMapper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReportsController extends Controller
{
    /**
     * One entry per raw-data report type — keeps the transactions() method
     * generic instead of repeating the same query/view logic four times.
     */
    private const TYPE_CONFIG = [
        'sales' => ['model' => SaleItem::class, 'date_column' => 'order_date', 'label' => 'Sales'],
        'purchase-orders' => ['model' => PurchaseOrderItem::class, 'date_column' => 'transaction_date', 'label' => 'Purchase Orders'],
        'invoices' => ['model' => InvoiceItem::class, 'date_column' => 'invoice_date', 'label' => 'Invoices'],
        'returns' => ['model' => ReturnItem::class, 'date_column' => 'transaction_date', 'label' => 'Returns'],
    ];

    public function index(Request $request)
    {
        $status = $request->query('status');

        $query = PurchaseRecommendation::query();

        if ($status !== null && $status !== '') {
            $query->where('stock_status', $status);
        }

        $recommendations = $query
            ->orderByRaw("FIELD(stock_status, 'CRITICAL', 'OUT OF STOCK', 'LOW', 'OK', 'OVERSTOCKED', 'NO MOVEMENT')")
            ->orderBy('sku')
            ->paginate(50)
            ->withQueryString();

        $statusCounts = PurchaseRecommendation::query()
            ->selectRaw('stock_status, COUNT(*) as count')
            ->groupBy('stock_status')
            ->pluck('count', 'stock_status');

        $lastUpdated = PurchaseRecommendation::max('updated_at');
        $totalCount = PurchaseRecommendation::count();

        return view('reports.index', compact('recommendations', 'statusCounts', 'lastUpdated', 'status', 'totalCount'));
    }

    public function transactions(Request $request, string $type)
    {
        $config = self::TYPE_CONFIG[$type] ?? throw new InvalidArgumentException("Unknown report type: {$type}");
        $model = $config['model'];

        $status = $request->query('status');
        $search = $request->query('search');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $dateColumn = $config['date_column'];

        // Excel-imported test rows predate the Zoho sync and share these
        // tables, so they're excluded by requiring the owning Import to be
        // real Zoho data — either the live API sync ('zoho') or a manually
        // imported Zoho report export ('zoho_export', e.g. the Stock
        // Movement/Purchase Orders by Item backfills). Filtering on
        // zoho_document_id alone used to achieve the same exclusion, but it
        // also wrongly hid 'zoho_export' rows, which are real Zoho data that
        // just has no per-document Zoho id (that report has no such field)
        // — this page showed only a fraction of actual synced history as a
        // result. Confirmed live (2026-09-12) after backfilling invoices.
        //
        // Inverted to a NOT IN against the small fake-import set (2026-10-15)
        // — this used to be whereIn($realZohoImportIds), a 9,000+-value IN
        // list (one per real Import row, growing daily). Confirmed live that
        // cost 6-11 SECONDS per query on sale_items' 1.6M rows, run three
        // times per page load (statusCounts/lastUpdated/totalCount) on top
        // of the actual list query — ~24s total. Only 9 Import rows in the
        // whole table are NOT real Zoho data (source='upload', a one-time
        // legacy test batch) — excluding those 9 instead of allow-listing
        // thousands cut the same query to well under a second.
        $fakeImportIds = Import::whereNotIn('source', ['zoho', 'zoho_export'])->pluck('id');

        $applyFilters = function ($query) use ($fakeImportIds, $search, $dateFrom, $dateTo, $dateColumn, $type) {
            $query->whereNotIn('import_id', $fakeImportIds);

            if ($search !== null && $search !== '') {
                $query->where(function ($q) use ($search, $type) {
                    $q->where('sku', 'like', "%{$search}%")
                        ->orWhere('document_number', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhereRaw('JSON_UNQUOTE(JSON_EXTRACT(extra, "$.item_name")) LIKE ?', ["%{$search}%"]);

                    if ($type === 'invoices') {
                        // Two sources, not one: the historical Analytics
                        // import only ever set extra.zoho_invoice_id, while
                        // the live Zoho sync sets the real zoho_document_id
                        // column instead and never touches extra for this —
                        // confirmed live (2026-09-16) after live-synced
                        // invoices (e.g. #84739) showed a blank Invoice ID
                        // because the search only checked the old source.
                        $q->orWhere('zoho_document_id', 'like', "%{$search}%")
                            ->orWhere('warehouse', 'like', "%{$search}%")
                            ->orWhereRaw('JSON_UNQUOTE(JSON_EXTRACT(extra, "$.zoho_invoice_id")) LIKE ?', ["%{$search}%"])
                            ->orWhereRaw('JSON_UNQUOTE(JSON_EXTRACT(extra, "$.city")) LIKE ?', ["%{$search}%"]);
                    }
                });
            }

            if ($dateFrom !== null && $dateFrom !== '') {
                $query->whereDate($dateColumn, '>=', $dateFrom);
            }

            if ($dateTo !== null && $dateTo !== '') {
                $query->whereDate($dateColumn, '<=', $dateTo);
            }

            return $query;
        };

        $query = $applyFilters($model::query());
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        $items = $query->orderByDesc($dateColumn)->paginate(50)->withQueryString();

        // Even with the NOT IN fix above, COUNT(*)/MAX()/GROUP BY over a
        // 1.6M-row table (sale_items) cost 5-10 SECONDS EACH regardless of
        // the WHERE clause — confirmed live the same day that an entirely
        // UNFILTERED count() was just as slow (~4.5s), so this is a genuine
        // full-table-scan cost, not something the import filter was
        // causing. totalCount/lastUpdated never depend on search/date/
        // status (see their query above — no $applyFilters), so they're
        // safe to cache as a single global value per type; a few minutes of
        // staleness on "Total rows: X / Last updated Y ago" is invisible to
        // a user, unlike a 10+ second page load. statusCounts DOES depend
        // on search/date (not status itself, since it's what the filter
        // pills are counting toward), so only the common no-filter case is
        // cached — an active search already narrows the scanned rows
        // enough that the live query is fast on its own.
        $hasActiveFilter = ($search !== null && $search !== '') || ($dateFrom !== null && $dateFrom !== '') || ($dateTo !== null && $dateTo !== '');
        $cacheTtl = now()->addMinutes(5);

        $statusCounts = $hasActiveFilter
            ? $applyFilters($model::query())->selectRaw('status, COUNT(*) as count')->groupBy('status')->pluck('count', 'status')
            : Cache::remember("reports.{$type}.status_counts", $cacheTtl, fn () => $applyFilters($model::query())
                ->selectRaw('status, COUNT(*) as count')->groupBy('status')->pluck('count', 'status'));

        $lastUpdated = Cache::remember("reports.{$type}.last_updated", $cacheTtl, fn () => $model::whereNotIn('import_id', $fakeImportIds)->max('updated_at'));
        $totalCount = Cache::remember("reports.{$type}.total_count", $cacheTtl, fn () => $model::whereNotIn('import_id', $fakeImportIds)->count());

        return view('reports.transactions', [
            'items' => $items,
            'statusCounts' => $statusCounts,
            'status' => $status,
            'search' => $search,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'type' => $type,
            'label' => $config['label'],
            'dateColumn' => $dateColumn,
            'lastUpdated' => $lastUpdated,
            'totalCount' => $totalCount,
            'isPurchaseOrders' => $type === 'purchase-orders',
        ]);
    }

    /**
     * All line items sharing one document number (e.g. every SKU on a
     * single invoice) — the "View Details" drill-down from the flat,
     * one-row-per-SKU transactions table.
     */
    public function document(Request $request, string $type, string $documentNumber)
    {
        $config = self::TYPE_CONFIG[$type] ?? throw new InvalidArgumentException("Unknown report type: {$type}");
        $model = $config['model'];

        // NOT IN against the small fake-import set, not an allow-list IN —
        // same reasoning as transactions() above, kept consistent even
        // though this query is already narrowed by document_number first.
        $fakeImportIds = Import::whereNotIn('source', ['zoho', 'zoho_export'])->pluck('id');

        // Default stays SKU order (matches Zoho's own stored line_items
        // order pre-rearrange). An optional ?sort=name switches to
        // alphabetical-by-item-name — the same ordering Zoho's own
        // "Re-Arrange Invoice Line Items" Deluge function applies to the
        // live invoice (2026-10-07), replicated here as a display-only
        // toggle rather than writing back to Zoho (this connection is
        // deliberately read-only for invoices — no write/update scope).
        // item_name lives in the JSON `extra` column (no dedicated column on
        // these line-item tables), hence JSON_UNQUOTE/JSON_EXTRACT rather
        // than a plain orderBy().
        $sort = $request->query('sort') === 'name' ? 'name' : 'sku';

        $items = $model::query()
            ->whereNotIn('import_id', $fakeImportIds)
            ->where('document_number', $documentNumber)
            ->when($sort === 'name',
                // Matches the Deluge function's own tie-break exactly — it
                // sorts a composite "itemName###originalIndex" key, so two
                // identically-named lines keep their original relative
                // order rather than landing in arbitrary order. `id` is this
                // table's own insertion order (rows are written in the same
                // sequence Zoho's line_items array arrived in), the closest
                // equivalent we have to that original index.
                fn ($q) => $q->orderByRaw("JSON_UNQUOTE(JSON_EXTRACT(extra, '$.item_name'))")->orderBy('id'),
                fn ($q) => $q->orderBy('sku'),
            )
            ->get();

        if ($items->isEmpty()) {
            abort(404);
        }

        $first = $items->first();

        // Line items only carry SKU-level detail — the richer document-level
        // fields (real-time status/balance/due date, plus every Analytics-
        // only operational field like e-invoice status, carton/pallet
        // counts, prepared/reviewed/delivered-by) live on invoice_documents
        // instead (see its migration/ImportInvoiceDocumentsAnalyticsReport).
        // Matched by invoice_number since that's the one identifier both
        // tables reliably share regardless of which path created either row.
        $invoiceDocument = $type === 'invoices'
            ? InvoiceDocument::where('invoice_number', $documentNumber)->first()
            : null;

        // Document-level fields (addresses, totals, custom fields, ...)
        // ZohoTransactionMapper::mapToRows() duplicates onto every line row
        // of this document — pulled once here (any row carries the same
        // values) so the view can show them in a single card instead of
        // repeating them under every line item's own "More" toggle. A
        // document synced before 2026-10-09 (when this capture was added)
        // simply won't have these keys in its extra yet — filtered out
        // below like any other absent field, not specially handled.
        $documentExtra = collect($first->extra ?? [])
            ->only(ZohoTransactionMapper::DOCUMENT_LEVEL_EXTRA_KEYS)
            ->filter(fn ($v) => $v !== null && $v !== '' && $v !== [])
            ->all();

        return view('reports.document', [
            'items' => $items,
            'type' => $type,
            'label' => $config['label'],
            'dateColumn' => $config['date_column'],
            'documentNumber' => $documentNumber,
            'sort' => $sort,
            'isPurchaseOrders' => $type === 'purchase-orders',
            'partyName' => $type === 'purchase-orders' ? $first->vendor_name : $first->customer_name,
            'date' => $first->{$config['date_column']},
            'status' => $first->status,
            'totalQuantity' => $items->sum('quantity'),
            'zohoInvoiceId' => $type === 'invoices' ? ($first->zoho_document_id ?? $first->extra['zoho_invoice_id'] ?? null) : null,
            'invoiceDocument' => $invoiceDocument,
            'documentExtra' => $documentExtra,
            'documentLevelKeys' => ZohoTransactionMapper::DOCUMENT_LEVEL_EXTRA_KEYS,
        ]);
    }

    /**
     * Items whose recorded price disagrees with SFDA's official regulated
     * price — see CheckSfdaPrices for how this table gets filled (a
     * standing snapshot, replaced each run, not a history log).
     */
    public function sfdaPriceChecks(Request $request)
    {
        $search = $request->query('search');

        $query = SfdaPriceCheck::query();

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhere('item_name', 'like', "%{$search}%")
                    ->orWhere('sfda_reg_no', 'like', "%{$search}%");
            });
        }

        $items = $query->orderByRaw('ABS(price_difference) DESC')
            ->paginate(50)
            ->withQueryString();

        $totalCount = SfdaPriceCheck::count();
        // sfda_price_checks (above) only ever gets a row when a price
        // MISMATCH is found — SfdaPriceCheckJob never writes to it on a
        // clean batch, so its own max(checked_at) is indistinguishable
        // between "never run" and "ran fine, found nothing wrong" (which is
        // the common, expected case; a checker that mostly finds nothing is
        // working as intended, not idle). sfda_item_snapshots gets a row
        // for every successfully-checked item regardless of outcome, so
        // it's the real "was this run recently" signal. Confirmed live
        // 2026-10-06: this page said "Never checked yet" while a real check
        // had completed as recently as 10 days earlier — which, combined
        // with the job's own silent failure that day forward (fixed
        // separately in SfdaPriceCheckJob), meant a genuine 10-day outage
        // read as "just hasn't run yet" instead of "broken," and went
        // unnoticed.
        $lastChecked = DB::table('sfda_item_snapshots')->max('checked_at');
        $regNoIssueCount = DB::table('sfda_reg_no_issues')->count();

        return view('reports.sfda-price-checks', [
            'items' => $items,
            'search' => $search,
            'totalCount' => $totalCount,
            'lastChecked' => $lastChecked,
            'regNoIssueCount' => $regNoIssueCount,
        ]);
    }

    /**
     * A running log of any field SFDA's own record for an item changed
     * between two checks (price, storage conditions, legal/marketing/
     * authorization status) — not just price mismatches. See
     * SfdaPriceCheckJob for how sfda_item_snapshots (the "last known state"
     * used to detect a change) and sfda_changes (this log) get filled.
     */
    public function sfdaChanges(Request $request)
    {
        $search = $request->query('search');

        $query = DB::table('sfda_changes');

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhere('item_name', 'like', "%{$search}%")
                    ->orWhere('field_changed', 'like', "%{$search}%");
            });
        }

        $items = $query->orderByDesc('detected_at')
            ->paginate(50)
            ->withQueryString();

        $totalCount = DB::table('sfda_changes')->count();
        $lastDetected = DB::table('sfda_changes')->max('detected_at');

        return view('reports.sfda-changes', [
            'items' => $items,
            'search' => $search,
            'totalCount' => $totalCount,
            'lastDetected' => $lastDetected,
        ]);
    }

    /**
     * Day-over-day changes across SFDA's ENTIRE drug database (~9,165
     * items, not just our own inventory) — see SfdaMarketSnapshotJob. A
     * separate report from sfda-changes (that one only ever covers items we
     * stock, compared against OUR rsp price); this one answers "what
     * changed in SFDA itself", independent of whether we carry the item —
     * direct manager request, 2026-10-07.
     */
    public function sfdaMarketChanges(Request $request)
    {
        $search = $request->query('search');
        $field = $request->query('field');

        $query = DB::table('sfda_market_changes');

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('sfda_reg_no', 'like', "%{$search}%")
                    ->orWhere('trade_name', 'like', "%{$search}%");
            });
        }

        if ($field !== null && $field !== '') {
            $query->where('field_changed', $field);
        }

        $items = $query->orderByDesc('detected_date')->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $totalCount = DB::table('sfda_market_changes')->count();
        $lastDetected = DB::table('sfda_market_changes')->max('detected_date');
        $lastSnapshotDate = DB::table('sfda_market_snapshots')->max('snapshot_date');
        $totalItemsTracked = DB::table('sfda_market_snapshots')
            ->where('snapshot_date', $lastSnapshotDate)
            ->count();

        return view('reports.sfda-market-changes', [
            'items' => $items,
            'search' => $search,
            'field' => $field,
            'totalCount' => $totalCount,
            'lastDetected' => $lastDetected,
            'lastSnapshotDate' => $lastSnapshotDate,
            'totalItemsTracked' => $totalItemsTracked,
        ]);
    }

    /**
     * Items whose extra.sfda_reg_no resolves to a DIFFERENT drug entirely at
     * SFDA — not a price mismatch, a wrong registration number recorded on
     * the item in Zoho itself (confirmed live 2026-10-07 directly against
     * Zoho's own item record, not a sync bug on our side). See
     * SfdaPriceCheckJob::itemNamesLikelyMatch() for the name-overlap check
     * that routes an item here instead of sfda_price_checks.
     */
    public function sfdaRegNoIssues(Request $request)
    {
        $search = $request->query('search');

        $query = DB::table('sfda_reg_no_issues');

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhere('item_name', 'like', "%{$search}%")
                    ->orWhere('sfda_reg_no', 'like', "%{$search}%")
                    ->orWhere('sfda_trade_name', 'like', "%{$search}%");
            });
        }

        $items = $query->orderBy('sku')->paginate(50)->withQueryString();

        $totalCount = DB::table('sfda_reg_no_issues')->count();
        $lastDetected = DB::table('sfda_reg_no_issues')->max('detected_at');

        return view('reports.sfda-reg-no-issues', [
            'items' => $items,
            'search' => $search,
            'totalCount' => $totalCount,
            'lastDetected' => $lastDetected,
        ]);
    }

    /**
     * Per-SKU stock by warehouse — filled from manual "Stock Summary
     * Report" exports (see ImportWarehouseStockReport), since Zoho's list-
     * endpoint inventory sync doesn't include the per-location breakdown.
     * Sorted by stock descending by default so the highest-stock SKUs for
     * whichever warehouse is picked surface first — the manager's ask was
     * specifically about spotting overstock per branch.
     */
    public function warehouses(Request $request)
    {
        $warehouses = InventoryWarehouseStock::query()
            ->distinct()
            ->orderBy('warehouse')
            ->pluck('warehouse');

        $selectedWarehouse = $request->query('warehouse', $warehouses->first());
        $search = $request->query('search');

        $query = InventoryWarehouseStock::query()->where('warehouse', $selectedWarehouse);

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhere('item_name', 'like', "%{$search}%");
            });
        }

        $items = $query->orderByDesc('closing_stock')->paginate(50)->withQueryString();

        $selectedTotals = InventoryWarehouseStock::where('warehouse', $selectedWarehouse)
            ->selectRaw('COUNT(*) as sku_count, SUM(closing_stock) as total_units')
            ->first();

        return view('reports.warehouses', [
            'warehouses' => $warehouses,
            'selectedWarehouse' => $selectedWarehouse,
            'search' => $search,
            'items' => $items,
            'skuCount' => $selectedTotals->sku_count ?? 0,
            'totalUnits' => $selectedTotals->total_units ?? 0,
        ]);
    }
}
