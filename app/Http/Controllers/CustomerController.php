<?php

namespace App\Http\Controllers;

use App\Models\ArLedgerEntry;
use App\Models\Customer;
use App\Models\CustomerChangeLog;
use App\Models\CustomerContactPerson;
use App\Models\CustomerDocumentInsight;
use App\Models\CustomerSalesBehavior;
use App\Models\CustomerSalesRecommendation;
use App\Models\Import;
use App\Models\InvoiceDocument;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Customer directory — its own top-level section (not nested under Reports)
 * per an explicit ask to mirror Zoho's own Contacts UI: search every
 * customer, open one to see its full Zoho-style detail plus every sales
 * transaction tied to it. See Customer model / the customers migration for
 * where this data comes from (a one-time Zoho Analytics export today, a
 * live Contacts API sync planned later).
 */
class CustomerController extends Controller
{
    /**
     * Line-item tables + their date column, searched by customer_name to
     * build the "Sales History" tab — see the linking-strategy decision on
     * customers.zoho_contact_id vs invoice_items.customer_name in the
     * Customer model docblock: exact name match today, zoho_customer_id
     * (once captured going forward) later.
     */
    private const SALES_SOURCES = [
        'sales' => ['table' => 'sale_items', 'date_column' => 'order_date', 'label' => 'Sales Order'],
        'invoices' => ['table' => 'invoice_items', 'date_column' => 'invoice_date', 'label' => 'Invoice'],
        'returns' => ['table' => 'return_items', 'date_column' => 'transaction_date', 'label' => 'Return'],
    ];

    /**
     * Balance-driven filters ("Overdue"/"Unpaid"/"Credit Limit Exceeded"),
     * mirroring Zoho's own default Customers views — buildable now because
     * invoice_documents (see that migration) finally gives us a real
     * per-invoice balance and due date to aggregate, unlike
     * customers.accounts_receivable/payments_due which turned out to be
     * Zoho reference ids, not usable numbers.
     */
    private const BALANCE_FILTERS = ['unpaid', 'overdue', 'credit_limit_exceeded'];

    /**
     * Recommended Credit Limit = Average Monthly Net Sales × (Payment Terms
     * + grace days) ÷ 30 + safety margin (Ahmed Mohsen's proposed formula,
     * 2026-10-02, replacing the old avgDailyNetSales × DSO one — see
     * buildCreditDso()'s docblock for why that was circular). Neither
     * constant below is a confirmed company policy figure yet — picked as
     * reasonable, clearly-flagged placeholders (15 days ≈ half a typical
     * 30-day term, as real breathing room without doubling exposure; 20% of
     * the customer's own average monthly sales rather than one flat SAR
     * figure, so it scales sensibly for a 10k/month customer and a
     * 1M/month one alike) so the formula isn't blocked waiting on exact
     * numbers — update these the moment real figures are confirmed.
     */
    private const RECOMMENDED_LIMIT_GRACE_DAYS = 15;

    private const RECOMMENDED_LIMIT_SAFETY_MARGIN_PCT = 0.20;

    /**
     * Multi-select facet filters on the Customers list — query param => column.
     * The first 5 are the reference portal's own filters (same order); the
     * last 2 are this app's pre-existing filters, kept per the "add theirs,
     * never remove ours" rule.
     */
    private const CUSTOMER_FACETS = [
        'status' => 'b.status',
        'sales_rep' => 'b.rep_name',
        'account_type' => 'b.account_type',
        'payment_term' => 'b.payment_terms_label',
        'management' => 'b.management',
        'customer_number' => 'b.customer_number',
        'sales_owner' => 'b.sales_owner',
    ];

    /** Facets whose pre-multi-select plain-string form was a partial (LIKE) match, kept that way for old links/bookmarks. */
    private const CUSTOMER_FACETS_LEGACY_LIKE = ['sales_rep', 'account_type', 'management', 'customer_number', 'sales_owner'];

    /**
     * Customers list — every column from Zoho Analytics, as the Lovable portal's customers_list():
     * zoho_raw_customers (refreshed hourly) for the customer itself, Outstanding Balance =
     * SUM(zoho_ar_sales.statement_amount) per customer, 0 when none. The earliest unpaid due date
     * behind the "Overdue" filter comes from zoho_ar_sales' invoice rows too. Customer 360 opens by the
     * Zoho customer id (see Customer::resolveRouteBinding), so every listed customer links.
     */
    private function customerListBase()
    {
        $ar = DB::table('zoho_ar_sales')->groupBy('customer_id')->select('customer_id')
            ->selectRaw('SUM(statement_amount) as outstanding_balance')
            ->selectRaw("MIN(CASE WHEN entity_type = 'invoice' AND balance > 0 THEN inv_balance_due_date END) as earliest_unpaid_due_date");

        return DB::table('zoho_raw_customers as c')
            ->leftJoinSub($ar, 'a', 'a.customer_id', '=', 'c.customer_id')
            ->select('c.customer_id', 'c.customer_name', 'c.display_name', 'c.company_name', 'c.status',
                'c.customer_number', 'c.rep_name', 'c.data_entry', 'c.sales_owner', 'c.account_type', 'c.management',
                'c.credit_limit as live_credit_limit', 'c.portal_enabled', 'a.earliest_unpaid_due_date')
            ->selectRaw('CAST(c.payments_due AS CHAR) as payment_terms_label, c.payments_due as payment_terms_days')
            ->selectRaw('COALESCE(a.outstanding_balance, 0) as outstanding_balance');
    }

    public function index(Request $request)
    {
        // Each facet param is either an array (multi-select, exact match — the
        // reference portal's filter style) or a plain string from an older
        // link such as the Sales Reps page's ?sales_rep=VACCANT.
        $selections = [];
        $legacyString = [];
        foreach (array_keys(self::CUSTOMER_FACETS) as $param) {
            $raw = $request->query($param);
            $legacyString[$param] = is_string($raw) && $raw !== '';
            $selections[$param] = collect(is_array($raw) ? $raw : [$raw])
                ->filter(fn ($v) => is_scalar($v) && (string) $v !== '')
                ->map(fn ($v) => (string) $v)->unique()->values()->all();
        }

        $search = trim((string) $request->query('search', ''));
        $customerName = $request->query('customer_name');
        $filter = $request->query('filter');
        $portal = $request->query('portal');

        $sortableColumns = ['customer_number', 'customer_name', 'rep_name', 'data_entry', 'sales_owner', 'account_type',
            'payment_terms_label', 'management', 'live_credit_limit', 'outstanding_balance', 'status'];
        $sort = in_array($request->query('sort'), $sortableColumns, true) ? 'b.'.$request->query('sort') : null;
        $sort = $sort === 'b.payment_terms_label' ? 'b.payment_terms_days' : $sort; // numeric order (30 before 120)
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        $applyNonFacetFilters = function ($q) use ($search, $customerName, $filter, $portal) {
            // The reference portal's single search box: matches any column shown in the table.
            if ($search !== '') {
                $q->where(function ($w) use ($search) {
                    foreach (['customer_name', 'display_name', 'company_name', 'customer_number', 'rep_name', 'data_entry',
                        'sales_owner', 'account_type', 'payment_terms_label', 'status', 'management'] as $column) {
                        $w->orWhere("b.{$column}", 'like', "%{$search}%");
                    }
                });
            }
            if ($customerName !== null && $customerName !== '') {
                $q->where(function ($w) use ($customerName) {
                    $w->where('b.customer_name', 'like', "%{$customerName}%")
                        ->orWhere('b.display_name', 'like', "%{$customerName}%")
                        ->orWhere('b.company_name', 'like', "%{$customerName}%");
                });
            }
            $this->applyBalanceFilter($q, $filter);
            $this->applyPortalFilter($q, $portal);
        };

        $query = DB::query()->fromSub($this->customerListBase(), 'b')->select('b.*')->tap($applyNonFacetFilters);
        foreach (self::CUSTOMER_FACETS as $param => $column) {
            if ($selections[$param] === []) {
                continue;
            }
            if ($legacyString[$param] && in_array($param, self::CUSTOMER_FACETS_LEGACY_LIKE, true)) {
                $query->where($column, 'like', '%'.$selections[$param][0].'%');
            } else {
                $query->whereIn($column, $selections[$param]);
            }
        }

        $customers = $query
            ->when($sort !== null, fn ($q) => $q->orderBy($sort, $direction))
            ->orderBy('b.customer_name')
            ->paginate(50)->withQueryString();

        // Facet option counts + the stats strip, the reference portal's way:
        // each filter's counts reflect every OTHER active filter, and the
        // stats reflect all of them. One small query (≈1.5k customers) with
        // the non-facet filters applied, then the facet logic in PHP.
        $facetRows = DB::query()->fromSub($this->customerListBase(), 'b')->tap($applyNonFacetFilters)
            ->get(array_map(fn ($param, $column) => "{$column} as {$param}", array_keys(self::CUSTOMER_FACETS), self::CUSTOMER_FACETS));

        $rowMatches = function ($row, ?string $exceptParam) use ($selections, $legacyString) {
            foreach ($selections as $param => $values) {
                if ($param === $exceptParam || $values === []) {
                    continue;
                }
                $value = (string) ($row->{$param} ?? '');
                $ok = $legacyString[$param] && in_array($param, self::CUSTOMER_FACETS_LEGACY_LIKE, true)
                    ? str_contains(mb_strtolower($value), mb_strtolower($values[0]))
                    : in_array($value, $values, true);
                if (! $ok) {
                    return false;
                }
            }

            return true;
        };

        $facetOptions = [];
        foreach (array_keys(self::CUSTOMER_FACETS) as $param) {
            $counts = [];
            foreach ($facetRows as $row) {
                $value = (string) ($row->{$param} ?? '');
                if ($value !== '' && $rowMatches($row, $param)) {
                    $counts[$value] = ($counts[$value] ?? 0) + 1;
                }
            }
            foreach ($selections[$param] as $selected) {
                $counts[$selected] ??= 0;
            }
            $options = [];
            foreach ($counts as $value => $count) {
                $options[] = ['value' => (string) $value, 'count' => $count];
            }
            usort($options, fn ($a, $b) => $param === 'payment_term' && is_numeric($a['value']) && is_numeric($b['value'])
                ? $a['value'] <=> $b['value']
                : strnatcasecmp($a['value'], $b['value']));
            $facetOptions[$param] = $options;
        }

        $matchingRows = $facetRows->filter(fn ($row) => $rowMatches($row, null));
        $stats = [
            'total' => $customers->total(),
            'active' => $matchingRows->where('status', 'Active')->count(),
            'inactive' => $matchingRows->where('status', 'Inactive')->count(),
            // Distinct non-blank Rep Name, as the reference portal's "Number of reps".
            'reps' => $matchingRows->pluck('sales_rep')->filter(fn ($v) => $v !== null && trim($v) !== '')->unique()->count(),
        ];

        return view('customers.index', [
            'customers' => $customers,
            'search' => $search,
            'customerName' => $customerName,
            'selections' => $selections,
            'facetOptions' => $facetOptions,
            'filter' => $filter,
            'portal' => $portal,
            'stats' => $stats,
            'totalUnfiltered' => DB::table('zoho_raw_customers')->count(),
            'sortKey' => $sort !== null ? $request->query('sort') : null,
            'sortDirection' => $direction,
            // Last successful Analytics pull of the Customers table.
            'lastUpdated' => DB::table('zoho_raw_sync_runs')->where('table_key', 'customers')->where('status', 'succeeded')->max('finished_at'),
        ]);
    }

    private function applyBalanceFilter($query, ?string $filter): void
    {
        match ($filter) {
            'unpaid' => $query->where('b.outstanding_balance', '>', 0),
            'overdue' => $query->where('b.outstanding_balance', '>', 0)
                ->whereNotNull('b.earliest_unpaid_due_date')
                ->where('b.earliest_unpaid_due_date', '<', now()->toDateString()),
            'credit_limit_exceeded' => $query->whereNotNull('b.live_credit_limit')
                ->whereColumn('b.outstanding_balance', '>', 'b.live_credit_limit'),
            default => null,
        };
    }

    private function applyPortalFilter($query, ?string $portal): void
    {
        // Analytics stores Portal Enabled as a Yes/No flag (1/0).
        match ($portal) {
            'enabled' => $query->where('b.portal_enabled', 1),
            'disabled' => $query->where('b.portal_enabled', 0),
            default => null,
        };
    }

    public function show(Request $request, Customer $customer)
    {
        $search = $request->query('sales_search');

        $realZohoImportIds = Import::whereIn('source', ['zoho', 'zoho_export'])->pluck('id');

        // Real per-invoice balance, joined by zoho_customer_id (exact id
        // match) — see invoice_documents migration for why this table
        // exists: customers.accounts_receivable/payments_due turned out to
        // be Zoho reference ids, not real balances, so this is the only
        // genuine "how much does this customer owe us" figure anywhere in
        // the system.
        // Excludes 'draft' status — confirmed live 2026-09-29 (manager
        // feedback: "Outstanding Balance correct for some customers,
        // incorrect for others"): draft invoices carry a real balance in
        // Zoho despite never having been sent, so summing them inflated
        // Outstanding Balance for any customer with an open draft (e.g.
        // CUS-3: +900,980 SAR from 10 drafts alone). A draft isn't a real
        // receivable yet.
        $invoiceDocs = InvoiceDocument::where('zoho_customer_id', $customer->zoho_contact_id)->where('status', '!=', 'draft');
        // customerLedgerBalance() (ar_ledger_entries, not invoice_documents) — see
        // that method's docblock for why: invoice_documents structurally can't
        // hold manual journal-entry adjustments, which turned out to be a real,
        // confirmed-against-live-Zoho gap for 81 customers.
        $outstandingBalance = $this->customerLedgerBalance($customer->zoho_contact_id);
        $overdueInvoiceCount = (clone $invoiceDocs)->where('balance', '>', 0)->count();
        $totalInvoiceCount = (clone $invoiceDocs)->count();

        $contactPersons = CustomerContactPerson::where('zoho_customer_id', $customer->zoho_contact_id)
            ->orderBy('email')
            ->get();

        $changeLogs = CustomerChangeLog::where('customer_id', $customer->id)
            ->orderByDesc('changed_at')
            ->paginate(50, ['*'], 'history_page');

        // The 4 customer classifications (Sales/Collection/Profitability/
        // Documents), synced from Zoho Analytics Query Tables built directly
        // from Dr. Ahmed's own SQL — see customer_classifications migration.
        // Keyed by the natural "Customer Number" business key rather than
        // our internal id, since that's how the classification data links
        // (this table predates any Zoho contact id being captured on it).
        $classifications = DB::table('customer_classifications')
            ->where('customer_number', $customer->customer_number)
            ->get()
            ->keyBy('type')
            ->map(fn ($row) => (object) [
                'classification' => $row->classification,
                'data' => json_decode($row->data, true),
                'synced_at' => $row->synced_at,
            ]);

        // Already-live "Elnasr Recommendations" data (see
        // ElnasrRecommendationsController), keyed by the real Zoho contact
        // id — this is the actual Insights/Recommendation engine output,
        // credit exposure, AR ledger, document-issue detail, and sales
        // behavior/cadence data. Reused here instead of duplicating any of
        // it, so the Customer 360 tabs show the same real numbers as that
        // page rather than inventing a second source of truth.
        $salesRecommendation = CustomerSalesRecommendation::where('zoho_customer_id', $customer->zoho_contact_id)->first();
        $creditUsage = $this->buildCreditUsage($customer, $outstandingBalance, $classifications['collection']->classification ?? null, $classifications['sales']->classification ?? null);
        $salesBehavior = CustomerSalesBehavior::where('zoho_customer_id', $customer->zoho_contact_id)->first();
        $documentInsightRows = CustomerDocumentInsight::where('zoho_customer_id', $customer->zoho_contact_id)->orderBy('id')->get();

        // Real last Sales Order date — manager feedback (2026-09-29):
        // Overview's "Last Sales Order" was actually showing the last
        // INVOICE date (from customer_classifications), not a real Sales
        // Order. sale_items has no customer id column yet, so matched by
        // customer_name (same convention as the Sales Orders tab).
        $lastSalesOrderDate = DB::table('sale_items')->whereNull('zoho_missing_since')
            ->where('customer_name', $customer->customer_name)
            ->max('order_date');

        // Same class of bug as "Last Sales Order" above, for the same
        // reason — confirmed wrong live 2026-10-04 (Ahmed Mohsen, CUS-1278
        // and 2 earlier customers): Overview's "Last Payment" read
        // customer_classifications' 'Last Settlement Date' (type=collection),
        // a once-daily external batch, while the Collection tab's "Today"
        // column already showed a payment from today live from
        // ar_ledger_entries — same data, two different freshness tiers on
        // the same page. Now reads the live table directly, same source the
        // Collection tab already uses.
        $lastPaymentDate = DB::table('ar_ledger_entries')
            ->where('zoho_customer_id', $customer->zoho_contact_id)
            ->where('category', 'COLLECTION')
            ->max('transaction_date');

        $arAging = $this->buildDueAgesTable($customer->zoho_contact_id);

        $salesKpiTable = $this->buildSalesKpiTable($customer->zoho_contact_id);
        $salesCharts = $this->buildSalesCharts($customer->zoho_contact_id);
        [$ytdHighestMonth, $ytdLowestMonth] = $this->ytdRevenueMonthExtremes($customer->zoho_contact_id);
        $profitabilityKpiTable = $this->buildProfitabilityKpiTable($customer->zoho_contact_id);
        $profitabilityCharts = $this->buildProfitabilityCharts($customer->zoho_contact_id);
        $collectionKpiTable = $this->buildCollectionKpiTable($customer->zoho_contact_id);
        $collectionCharts = $this->buildCollectionCharts($customer->zoho_contact_id);

        // return_items/sale_items have no price column of their own, but
        // inventory_items (the real product catalog, confirmed live
        // 2026-09-26: stable price per SKU, not fabricated) does — joining
        // by sku lets every "Value"/monetary KPI on these two tabs be a real
        // estimate (catalog price × quantity) instead of a hard "Coming
        // Soon" block. Clearly labeled as an estimate in the UI since it's
        // the current catalog price, not the exact price on that specific
        // transaction (which isn't captured anywhere).
        $skuPrices = $this->skuPriceMap();

        $returnKpiTable = $this->buildReturnKpiTable($customer->zoho_contact_id);
        $returnCharts = $this->buildReturnCharts($customer->zoho_contact_id);
        $salesOrdersKpiTable = $this->buildQuantityKpiTable('sale_items', 'order_date', $customer->customer_name, 'Order Quantity', 'Order Count');
        foreach ($this->buildSalesOrdersStatusTable($customer->customer_name, $skuPrices) as $period => $extra) {
            $salesOrdersKpiTable[$period] = array_merge($salesOrdersKpiTable[$period] ?? [], $extra);
        }
        $salesOrdersCharts = $this->buildSalesOrdersCharts($customer->customer_name, $skuPrices);
        $overviewTargets = $this->buildOverviewTargets($customer->zoho_contact_id, $customer->extra ?? []);
        $arKpiTable = $this->buildArKpiTable($customer->zoho_contact_id, (float) $outstandingBalance);
        $arSnapshot = $this->buildArSnapshot($customer->zoho_contact_id, (float) $outstandingBalance);
        $creditDso = $this->buildCreditDso($customer->zoho_contact_id, $customer, $outstandingBalance, $classifications['collection']->classification ?? null, $classifications['sales']->classification ?? null);
        $dueAgesChart = $this->buildDueAgesChart($customer->zoho_contact_id);
        $itemPerformanceOwners = $this->buildItemPerformanceData($customer->zoho_contact_id);

        $insights = $this->buildInsights(
            $classifications,
            $salesRecommendation,
            $creditUsage,
            $documentInsightRows,
            $arAging,
            $overdueInvoiceCount,
        );

        // Same id, reused below to match invoice_items accurately instead
        // of by customer_name — invoice_items has no customer id column of
        // its own yet, but invoice_documents.invoice_number reliably maps
        // to invoice_items.document_number, and invoice_documents IS keyed
        // by the real customer id. Confirmed live (2026-09-22) that
        // customer_name matching alone silently missed real invoices for at
        // least one customer (Financial tab showed real invoices tracked,
        // Sales History showed zero) — almost certainly a formatting
        // difference between the two exports' customer name text. sale_items
        // and return_items don't have an equivalent document-level table yet,
        // so they still match by name only.
        $invoiceNumbersForCustomer = (clone $invoiceDocs)->whereNotNull('invoice_number')->pluck('invoice_number');

        // Sales History now matches the same rolling-last-12-months window
        // used by every chart on this page (was previously unbounded —
        // showed the customer's entire lifetime history).
        $salesHistoryStart = Carbon::today()->subMonthsNoOverflow(11)->startOfMonth();

        $unionQuery = null;

        foreach (self::SALES_SOURCES as $type => $config) {
            // $type is one of this class's own hardcoded array keys
            // ('sales'/'invoices'/'returns'), never user input — embedded as
            // a literal instead of a bound "?" placeholder on purpose. A
            // bound placeholder here broke pagination: getCountForPagination()
            // wraps this whole union as a subquery and mergeBindings() groups
            // bindings by clause TYPE (all "select" bindings together, then
            // all "where" bindings together), which doesn't match the actual
            // left-to-right order "?" placeholders appear in a hand-embedded
            // raw SQL string spanning three unioned branches — confirmed live
            // (2026-09-22): values landed on the wrong placeholders entirely
            // (a customer's name bound to `customer_name = 53`, an Arabic
            // company name bound to the `type` column) and MySQL rejected the
            // final query outright once the placeholder/value counts also
            // drifted (SQLSTATE[HY093]: Invalid parameter number).
            $sub = DB::table($config['table'])
                ->selectRaw("'{$type}' as type, document_number, sku, quantity, {$config['date_column']} as transaction_date, status")
                ->whereIn('import_id', $realZohoImportIds)
                ->where($config['date_column'], '>=', $salesHistoryStart->toDateString());

            if ($type === 'invoices') {
                $sub->whereIn('document_number', $invoiceNumbersForCustomer);
            } else {
                $sub->where('customer_name', $customer->customer_name);
            }

            if ($search !== null && $search !== '') {
                $sub->where(function ($q) use ($search) {
                    $q->where('document_number', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            }

            $unionQuery = $unionQuery === null ? $sub : $unionQuery->unionAll($sub);
        }

        $salesHistory = DB::table(DB::raw("({$unionQuery->toSql()}) as combined"))
            ->mergeBindings($unionQuery)
            ->orderByDesc('transaction_date')
            ->paginate(50, ['*'], 'sales_page')
            ->withQueryString();

        $salesCountsByType = [];
        foreach (self::SALES_SOURCES as $type => $config) {
            $countQuery = DB::table($config['table'])->whereIn('import_id', $realZohoImportIds)
                ->where($config['date_column'], '>=', $salesHistoryStart->toDateString());
            if ($type === 'invoices') {
                $countQuery->whereIn('document_number', $invoiceNumbersForCustomer);
            } else {
                $countQuery->where('customer_name', $customer->customer_name);
            }
            $salesCountsByType[$type] = $countQuery->count();
        }

        // Fields Zoho's live contact response carries that aren't already
        // surfaced as their own dedicated column/section on this page —
        // live_contact_data already stores the FULL raw response
        // (ZohoContactMapper::mapToCustomerAttributes()), so this is a
        // display-only filter, not a new sync capture. Deliberately a
        // curated business-relevant subset, not every one of the ~190 raw
        // keys — most of the rest (lock_details, approvers_list,
        // submitted_by_photo_url, zohopeople_client_id, ...) is Zoho's own
        // UI/workflow plumbing, meaningless on a report page. A customer
        // never synced live yet (no live_contact_data) just shows nothing
        // here, same as any other absent field on this page.
        $customerExtraKeys = [
            'email', 'phone', 'mobile', 'website', 'designation', 'department',
            'source', 'sales_channel', 'vat_reg_no', 'tax_reg_no', 'pricebook_name',
            'notes', 'outstanding_payable_amount', 'unused_credits_receivable_amount',
        ];
        $customerExtra = collect($customer->live_contact_data ?? [])
            ->only($customerExtraKeys)
            ->filter(fn ($v) => $v !== null && $v !== '' && $v !== [])
            ->all();

        return view('customers.show', [
            'customer' => $customer,
            'customerExtra' => $customerExtra,
            'salesHistory' => $salesHistory,
            'salesSearch' => $search,
            'salesCountsByType' => $salesCountsByType,
            'sourceLabels' => array_map(fn ($c) => $c['label'], self::SALES_SOURCES),
            'outstandingBalance' => $outstandingBalance,
            'overdueInvoiceCount' => $overdueInvoiceCount,
            'totalInvoiceDocumentCount' => $totalInvoiceCount,
            'contactPersons' => $contactPersons,
            'changeLogs' => $changeLogs,
            'classifications' => $classifications,
            'salesRecommendation' => $salesRecommendation,
            'creditUsage' => $creditUsage,
            'salesBehavior' => $salesBehavior,
            'documentInsightRows' => $documentInsightRows,
            'arAging' => $arAging,
            'insights' => $insights,
            'salesKpiTable' => $salesKpiTable,
            'salesCharts' => $salesCharts,
            'ytdHighestMonth' => $ytdHighestMonth,
            'ytdLowestMonth' => $ytdLowestMonth,
            'profitabilityKpiTable' => $profitabilityKpiTable,
            'profitabilityCharts' => $profitabilityCharts,
            'collectionKpiTable' => $collectionKpiTable,
            'collectionCharts' => $collectionCharts,
            'returnKpiTable' => $returnKpiTable,
            'returnCharts' => $returnCharts,
            'salesOrdersKpiTable' => $salesOrdersKpiTable,
            'salesOrdersCharts' => $salesOrdersCharts,
            'overviewTargets' => $overviewTargets,
            'arKpiTable' => $arKpiTable,
            'arSnapshot' => $arSnapshot,
            'dueAgesChart' => $dueAgesChart,
            'itemPerformanceOwners' => $itemPerformanceOwners,
            'creditDso' => $creditDso,
            'lastSalesOrderDate' => $lastSalesOrderDate,
            'lastPaymentDate' => $lastPaymentDate,
        ]);
    }

    /**
     * Real per-period Sales KPI table, computed straight from
     * sales_item_performance (the live-synced, complete invoice line-item
     * history) — matches the mockup's Sales tab table shape (KPI x 7
     * periods). Revenue Contribution % is a real per-period computation
     * (this customer's Net Sales ÷ company-wide Net Sales for the same
     * period). Revenue Rank and Average Purchase Interval are point-in-time
     * snapshots (not truly per-period), shown once in the Lifetime column
     * only, reusing numbers already computed elsewhere on this page rather
     * than fabricating a per-period history we don't have. Revenue
     * Volatility % is the coefficient of variation (stddev/mean) of the
     * trailing-12-month Net Sales series, also a single snapshot figure.
     *
     * @return array<string, array<string, string>> period label => [kpi label => formatted value]
     */
    /**
     * Sales tab KPI table — rebuilt from ar_ledger_entries.statement_amount
     * (category=SALES, entity_type invoice/creditnote), replacing the old
     * sales_item_performance-based calc. Confirmed live (2026-09-30) against
     * Zoho's own "Sales by Customer" report and a full manual reconciliation
     * for CUS-8: the old source didn't match Zoho's real numbers (e.g. YTD
     * Net Sales showed SAR 6,323,452 vs Zoho's actual SAR 6,216,497.06);
     * this source matches exactly. Also matches Zoho's report in being
     * VAT-inclusive (statement_amount includes VAT on some line items).
     *
     * Sales Average/Median/Revenue Volatility % are computed on a
     * zero-filled per-month series for Lifetime/Previous Year/YTD (divided
     * by the number of calendar months touched) and a zero-filled per-day
     * series for Previous Month/MTD — not a per-invoice average — per the
     * reconciliation doc. Yesterday/Today are single-day periods with no
     * meaningful average/median/volatility.
     *
     * Revenue Rank - Company is now a live per-period rank + the count of
     * customers with positive Net Sales in that same period (e.g.
     * "#2 / 752"), not a Lifetime-only snapshot borrowed from the separate
     * Elnasr Recommendations dataset.
     *
     * SKU Count / Average SKU Revenue and Average Purchase Interval were
     * dropped from this table — the former duplicates the Item Performance
     * tab (manager feedback), the latter isn't part of the reconciled
     * definition.
     */
    private function buildSalesKpiTable(?string $zohoCustomerId): array
    {
        if ($zohoCustomerId === null) {
            return [];
        }

        $firstSaleDate = DB::table('ar_ledger_entries')
            ->where('zoho_customer_id', $zohoCustomerId)
            ->where('category', 'SALES')
            ->whereIn('entity_type', ['invoice', 'creditnote'])
            ->min('transaction_date');

        $today = Carbon::today();
        $lifetimeStart = $firstSaleDate ? Carbon::parse($firstSaleDate)->startOfDay() : $today->copy();

        $ranges = [
            'Lifetime' => [$lifetimeStart, $today, 'month'],
            'Previous Year' => [$today->copy()->subYear()->startOfYear(), $today->copy()->subYear()->endOfYear(), 'month'],
            'YTD' => [$today->copy()->startOfYear(), $today, 'month'],
            'Previous Month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth(), 'day'],
            'MTD' => [$today->copy()->startOfMonth(), $today, 'day'],
            'Yesterday' => [$today->copy()->subDay(), $today->copy()->subDay(), null],
            'Today' => [$today->copy(), $today->copy(), null],
        ];

        $money = fn (float $v) => 'SAR '.number_format($v, 0);
        $table = [];
        $firstActivity = $this->customerFirstActivityDate($zohoCustomerId);

        foreach ($ranges as $label => [$from, $to, $bucket]) {
            // See customerFirstActivityDate()'s docblock — a period that
            // ended before this customer's very first transaction (any
            // category) isn't "zero sales", it's "not a customer yet";
            // every field in the row reflects that uniformly.
            if ($firstActivity !== null && $to->lt($firstActivity)) {
                $table[$label] = [
                    'Net Sales' => '—',
                    'Gross Sales' => '—',
                    'Sales Average' => '—',
                    'Sales Median' => '—',
                    'Revenue Volatility %' => '—',
                    'Revenue Contribution %' => '—',
                    'Revenue Rank - Company' => '—',
                    'Invoice Count' => '—',
                    'Average Invoice Value' => '—',
                    'Largest Invoice' => '—',
                ];

                continue;
            }

            $netSales = (float) DB::table('ar_ledger_entries')
                ->where('zoho_customer_id', $zohoCustomerId)->where('category', 'SALES')
                ->whereIn('entity_type', ['invoice', 'creditnote'])
                ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
                ->sum('statement_amount');

            $grossSales = (float) DB::table('ar_ledger_entries')
                ->where('zoho_customer_id', $zohoCustomerId)->where('category', 'SALES')
                ->where('entity_type', 'invoice')
                ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
                ->sum('statement_amount');

            $invoiceCount = DB::table('ar_ledger_entries')
                ->where('zoho_customer_id', $zohoCustomerId)->where('category', 'SALES')
                ->where('entity_type', 'invoice')
                ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
                ->count();

            $largestInvoice = DB::table('ar_ledger_entries')
                ->where('zoho_customer_id', $zohoCustomerId)->where('category', 'SALES')
                ->where('entity_type', 'invoice')
                ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
                ->max('statement_amount');

            // Company-wide totals for Lifetime are NOT bounded by this
            // customer's own first-sale date — confirmed live (2026-09-30)
            // against the reconciliation doc's worked numbers: bounding the
            // company sum to this customer's Lifetime start undercounts it
            // (excludes company activity from customers whose own history
            // starts earlier), which skewed Contribution %/Rank. Other
            // periods (Previous Year/YTD/etc.) are fixed calendar ranges
            // already, so this only matters for Lifetime.
            $companyFrom = $label === 'Lifetime' ? null : $from;
            $companyNetSales = (float) DB::table('ar_ledger_entries')
                ->where('category', 'SALES')->whereIn('entity_type', ['invoice', 'creditnote'])
                ->when($companyFrom !== null, fn ($q) => $q->where('transaction_date', '>=', $companyFrom->toDateString()))
                ->where('transaction_date', '<=', $to->toDateString())
                ->sum('statement_amount');

            $rankInfo = $this->salesRevenueRankAndCount($zohoCustomerId, $companyFrom, $to);

            // Averaging series starts no earlier than this customer's own
            // first sale — confirmed wrong live 2026-10-04 (Ahmed Mohsen,
            // CUS-1278, a ~5-month-old customer): YTD's series was still
            // zero-filling January through the customer's actual first
            // month, diluting Sales Average by counting months the customer
            // didn't exist yet as "zero activity" rather than "not a
            // customer yet." $seriesFrom > $to (the period ended before this
            // customer's first sale, e.g. Previous Year for someone who
            // joined this year) means they had no presence in this period at
            // all — average/median/volatility correctly stay '—'.
            $seriesFrom = $from->lt($lifetimeStart) ? $lifetimeStart->copy() : $from;

            if ($bucket !== null && $seriesFrom->lte($to)) {
                $series = $this->salesPeriodSeries($zohoCustomerId, $seriesFrom, $to, $bucket);
                $unit = $bucket === 'month' ? '/mo' : '/day';
                $avg = $money((float) $series->avg()).' '.$unit;
                $median = $money($this->medianOf($series)).' '.$unit;
                $volatility = $this->volatilityPercentOf($series);
            } else {
                $avg = '—';
                $median = '—';
                $volatility = '—';
            }

            $table[$label] = [
                'Net Sales' => $money($netSales),
                'Gross Sales' => $money($grossSales),
                'Sales Average' => $avg,
                'Sales Median' => $median,
                'Revenue Volatility %' => $volatility,
                'Revenue Contribution %' => $companyNetSales > 0 ? number_format($netSales / $companyNetSales * 100, 2).'%' : '—',
                'Revenue Rank - Company' => $rankInfo['rank'] !== null ? '#'.$rankInfo['rank'].' / '.number_format($rankInfo['total']) : '—',
                'Invoice Count' => number_format($invoiceCount),
                'Average Invoice Value' => $invoiceCount > 0 ? $money($grossSales / $invoiceCount) : '—',
                'Largest Invoice' => $largestInvoice !== null ? $money((float) $largestInvoice) : '—',
            ];
        }

        return $table;
    }

    /**
     * Zero-filled per-month or per-day Net Sales series (invoices + credit
     * notes) for a date range — the basis for Sales Average/Median/Revenue
     * Volatility %, so periods/days with no activity count as 0 rather than
     * being skipped (per the reconciliation doc).
     */
    private function salesPeriodSeries(string $zohoCustomerId, Carbon $from, Carbon $to, string $bucket): \Illuminate\Support\Collection
    {
        $query = DB::table('ar_ledger_entries')
            ->where('zoho_customer_id', $zohoCustomerId)->where('category', 'SALES')
            ->whereIn('entity_type', ['invoice', 'creditnote'])
            ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()]);

        $series = collect();

        if ($bucket === 'month') {
            $rows = $query->selectRaw('YEAR(transaction_date) as y, MONTH(transaction_date) as m')
                ->selectRaw('SUM(statement_amount) as total')
                ->groupBy('y', 'm')->get()
                ->keyBy(fn ($r) => sprintf('%04d-%02d', $r->y, $r->m));

            $cursor = $from->copy()->startOfMonth();
            $end = $to->copy()->startOfMonth();
            while ($cursor->lte($end)) {
                $series->push((float) ($rows->get($cursor->format('Y-m'))->total ?? 0));
                $cursor->addMonthNoOverflow();
            }
        } else {
            $rows = $query->selectRaw('DATE(transaction_date) as d')
                ->selectRaw('SUM(statement_amount) as total')
                ->groupBy('d')->get()
                ->keyBy(fn ($r) => (string) $r->d);

            $cursor = $from->copy();
            while ($cursor->lte($to)) {
                $series->push((float) ($rows->get($cursor->toDateString())->total ?? 0));
                $cursor->addDay();
            }
        }

        return $series;
    }

    /**
     * This customer's earliest transaction_date across EVERY category
     * (SALES, COLLECTION, etc.) — not the category-specific "first sale" /
     * "first collection" dates each KPI table's own $lifetimeStart already
     * uses for ITS OWN Lifetime row. Used to detect a period that predates
     * the customer existing at all (e.g. "Previous Year" for someone who
     * joined this year), so every field in that row can be forced to '—'
     * uniformly — confirmed live 2026-10-06 (manager report, CUS-1221, a
     * 6-month-old customer): Collection Average/Ratio/Median already
     * correctly showed '—' for Previous Year via their own zero-division
     * guards, but Collection itself, Payment Count and Collection
     * Contribution % don't divide by anything — they just summed real rows,
     * which for a not-yet-a-customer period is legitimately empty and comes
     * back 0/SAR 0/0.00%, not undefined — so those three looked like "this
     * customer existed and did nothing" instead of "wasn't a customer yet",
     * inconsistent with the other fields on the same row. One check per row
     * covering every field is the fix, not patching each field's fallback
     * individually (which is how the three got missed the first time).
     */
    private function customerFirstActivityDate(string $zohoCustomerId): ?Carbon
    {
        $date = DB::table('ar_ledger_entries')
            ->where('zoho_customer_id', $zohoCustomerId)
            ->min('transaction_date');

        return $date !== null ? Carbon::parse($date)->startOfDay() : null;
    }

    private function medianOf(\Illuminate\Support\Collection $values): float
    {
        $sorted = $values->sort()->values();
        $n = $sorted->count();
        if ($n === 0) {
            return 0.0;
        }

        return $n % 2 === 1 ? $sorted[intdiv($n, 2)] : ($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2;
    }

    private function volatilityPercentOf(\Illuminate\Support\Collection $values): string
    {
        if ($values->count() < 2) {
            return '—';
        }

        $mean = $values->avg();
        if ($mean <= 0) {
            return '—';
        }

        // Sample standard deviation (N−1), not population (N) — confirmed
        // live (2026-09-30) against the reconciliation doc's worked
        // numbers: population stdev undershot both examples (61.6% vs
        // 62.6% Lifetime, 33.1% vs 35.1% YTD), sample stdev matched exactly.
        $n = $values->count();
        $variance = $values->map(fn ($v) => ($v - $mean) ** 2)->sum() / ($n - 1);

        return number_format(sqrt($variance) / $mean * 100, 1).'%';
    }

    /**
     * Live per-period company-wide rank (by Net Sales, invoices + credit
     * notes) among customers with positive Net Sales in that same period,
     * plus that positive-count as the rank's denominator (e.g. "#2 / 752") —
     * replaces the old Lifetime-only snapshot borrowed from the separate
     * Elnasr Recommendations dataset.
     *
     * @return array{rank: ?int, total: int}
     */
    private function salesRevenueRankAndCount(?string $zohoCustomerId, ?Carbon $from, Carbon $to): array
    {
        $totals = DB::table('ar_ledger_entries')
            ->where('category', 'SALES')->whereIn('entity_type', ['invoice', 'creditnote'])
            ->when($from !== null, fn ($q) => $q->where('transaction_date', '>=', $from->toDateString()))
            ->where('transaction_date', '<=', $to->toDateString())
            ->select('zoho_customer_id')->selectRaw('SUM(statement_amount) as total')
            ->groupBy('zoho_customer_id')
            ->havingRaw('SUM(statement_amount) > 0')
            ->orderByDesc('total')
            ->pluck('zoho_customer_id')->values();

        $position = $zohoCustomerId !== null ? $totals->search($zohoCustomerId) : false;

        return ['rank' => $position === false ? null : $position + 1, 'total' => $totals->count()];
    }

    /**
     * Rolling last-12-months monthly totals for the Sales tab's charts, from
     * ar_ledger_entries (same source/columns as buildSalesKpiTable() — see
     * its docblock for why the old sales_item_performance source was wrong).
     * SKU Count / Average SKU Revenue dropped (duplicates Item Performance
     * tab, manager feedback).
     *
     * @return array<string, array<int, array{label: string, value: float}>>
     */
    /**
     * Overview tab's "YTD Highest/Lowest Revenue Month" — a dedicated true
     * calendar-year (Jan 1 → today) monthly Net Sales series, not a reuse of
     * buildSalesCharts()'s rolling trailing-12-months window. Confirmed live
     * 2026-09-30 (manager report): reusing that rolling chart let
     * "YTD Lowest Revenue Month" land on a month from LAST year (e.g. Nov)
     * that isn't part of this year's YTD at all. The current (still
     * in-progress) month is also excluded — a handful of days' sales is not
     * comparable to a full month and would unfairly look "lowest".
     *
     * @return array{0: ?array{label: string, value: float}, 1: ?array{label: string, value: float}}
     */
    private function ytdRevenueMonthExtremes(?string $zohoCustomerId): array
    {
        if ($zohoCustomerId === null) {
            return [null, null];
        }

        $today = Carbon::today();
        $rows = DB::table('ar_ledger_entries')
            ->where('zoho_customer_id', $zohoCustomerId)->where('category', 'SALES')
            ->whereIn('entity_type', ['invoice', 'creditnote'])
            ->whereBetween('transaction_date', [$today->copy()->startOfYear()->toDateString(), $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()])
            ->selectRaw('MONTH(transaction_date) as m')
            ->selectRaw('SUM(statement_amount) as total')
            ->groupBy('m')->get();

        $series = $rows->map(fn ($r) => [
            'label' => Carbon::createFromDate(null, (int) $r->m, 1)->format('M'),
            'value' => (float) $r->total,
        ])->filter(fn ($p) => $p['value'] > 0)->values();

        // Excludes the customer's own first calendar month when it's a
        // partial month (joined partway through, not on the 1st) — same
        // reasoning as the current in-progress month already being excluded
        // above ($today->subMonthNoOverflow()->endOfMonth() as the upper
        // bound): a handful of days' sales isn't comparable to a full
        // month and would unfairly look "lowest". Confirmed live
        // 2026-10-06 (manager report, CUS-1221, joined April 27): April's
        // 4 days of revenue (1,298.74) won "YTD Lowest Revenue Month"
        // against every full month after it. Only matters when that first
        // month falls inside this year's YTD window.
        $firstActivity = $this->customerFirstActivityDate($zohoCustomerId);
        if ($firstActivity !== null && $firstActivity->day > 1 && $firstActivity->year === $today->year) {
            $series = $series->reject(fn ($p) => $p['label'] === $firstActivity->format('M'))->values();
        }

        if ($series->isEmpty()) {
            return [null, null];
        }

        return [
            $series->sortByDesc('value')->first(),
            $series->sortBy('value')->first(),
        ];
    }

    private function buildSalesCharts(?string $zohoCustomerId): array
    {
        if ($zohoCustomerId === null) {
            return [];
        }

        $start = Carbon::today()->subMonthsNoOverflow(11)->startOfMonth();

        $invoiceRows = DB::table('ar_ledger_entries')
            ->where('zoho_customer_id', $zohoCustomerId)->where('category', 'SALES')
            ->where('entity_type', 'invoice')
            ->where('transaction_date', '>=', $start->toDateString())
            ->selectRaw('YEAR(transaction_date) as y, MONTH(transaction_date) as m')
            ->selectRaw('SUM(statement_amount) as gross, COUNT(*) as cnt')
            ->groupBy('y', 'm')->get()
            ->keyBy(fn ($r) => sprintf('%04d-%02d', $r->y, $r->m));

        $netSalesByMonth = DB::table('ar_ledger_entries')
            ->where('zoho_customer_id', $zohoCustomerId)->where('category', 'SALES')
            ->whereIn('entity_type', ['invoice', 'creditnote'])
            ->where('transaction_date', '>=', $start->toDateString())
            ->selectRaw('YEAR(transaction_date) as y, MONTH(transaction_date) as m')
            ->selectRaw('SUM(statement_amount) as net_total')
            ->groupBy('y', 'm')->get()
            ->keyBy(fn ($r) => sprintf('%04d-%02d', $r->y, $r->m));

        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $months[] = Carbon::today()->subMonthsNoOverflow($i)->startOfMonth();
        }

        $series = ['Net Sales' => [], 'Invoice Count' => [], 'Average Invoice Value' => []];

        foreach ($months as $month) {
            $key = $month->format('Y-m');
            $label = $month->format('M');
            $inv = $invoiceRows->get($key);
            $gross = (float) ($inv->gross ?? 0);
            $count = (int) ($inv->cnt ?? 0);
            $netSales = (float) ($netSalesByMonth->get($key)->net_total ?? 0);

            $series['Net Sales'][] = ['label' => $label, 'value' => $netSales];
            $series['Invoice Count'][] = ['label' => $label, 'value' => $count];
            $series['Average Invoice Value'][] = ['label' => $label, 'value' => $count > 0 ? $gross / $count : 0];
        }

        return $series;
    }

    /**
     * The 7 standard period date-ranges shared by every KPI table on this
     * page (Lifetime has no bounds). Anchored on today() each request, not
     * cached — this is a low-traffic detail page, not worth memoizing.
     *
     * @return array<string, array{0: ?Carbon, 1: ?Carbon}>
     */
    private function periodRanges(): array
    {
        $today = Carbon::today();

        return [
            'Lifetime' => [null, null],
            'Previous Year' => [$today->copy()->subYear()->startOfYear(), $today->copy()->subYear()->endOfYear()],
            'YTD' => [$today->copy()->startOfYear(), $today],
            'Previous Month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            'MTD' => [$today->copy()->startOfMonth(), $today],
            'Yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            'Today' => [$today, $today],
        ];
    }

    /**
     * Real per-period Profitability KPI table, from ar_ledger_entries
     * (sales_exc_vat/cogs are reliable per-transaction fields; gross_profit
     * is NOT — see below). Confirmed live 2026-09-30 against a manual
     * reconciliation for CUS-8: the query used to sum sales_exc_vat across
     * BOTH invoice and credit note rows — credit notes always carry
     * gross_profit=0 but a negative sales_exc_vat, so including them shrank
     * the denominator without touching the numerator and inflated Gross
     * Margin % (24.65% vs the real 23.54% for this customer's Lifetime). The
     * fix applied that day (restrict to entity_type='invoice' and trust the
     * stored gross_profit column) traded that bug for a different one,
     * confirmed live 2026-10-05 (Dr. Ahmed, CUS-1526): an invoice fully
     * reversed by a credit note (86162, -167.42 loss, closed same-day by
     * CN-09231) still counted its FULL original loss, because the
     * reversing credit note's own gross_profit is ALSO hard-zeroed at the
     * source — confirmed this isn't our mapping (ImportArLedger pulls
     * gross_profit as a raw column straight off Zoho Analytics' own AR
     * Ledger view, same as sales_exc_vat/cogs, no computation of our own)
     * — so Zoho's own report can't be fixed from here, and trusting that
     * column at all was the real defect, not which entity_type to filter.
     * sales_exc_vat and cogs ARE reliable on every row type including
     * credit notes (confirmed: CN-09231 carries real, non-zero values that
     * net almost exactly against the invoice it reverses) — so Gross
     * Profit/Margin/Contribution/Rank below are now computed as
     * SUM(sales_exc_vat) - SUM(cogs) across invoice AND credit note rows
     * together, never by summing the stored gross_profit column. This
     * fixes both bugs at once: a reversed invoice nets to ~0 like it
     * should, and the sales denominator and profit numerator stay
     * consistent with each other (both net of returns), so neither can
     * drift apart the way the original CUS-8 bug did.
     *
     * Average GP% per Invoice stays scoped to entity_type='invoice' only,
     * unchanged — it's a per-invoice average of individual real invoices'
     * own margins (a different question than aggregate profit), and
     * additionally excludes the handful of invoices whose Sales Exc Vat is
     * near zero (confirmed live: 14 such invoices, e.g. one with SAR 6.90
     * in sales against a real loss, producing a nonsensical -689,528%
     * "margin" that wrecked the average). GP Rank is live per period +
     * company count (e.g. "#2 / 1,173"), not a Lifetime-only snapshot, and
     * now uses the same net-of-returns recompute via profitabilityRankAndCount().
     *
     * @return array<string, array<string, string>>
     */
    private function buildProfitabilityKpiTable(?string $zohoCustomerId): array
    {
        if ($zohoCustomerId === null) {
            return [];
        }

        $money = fn (float $v) => 'SAR '.number_format($v, 0);
        $table = [];
        $firstActivity = $this->customerFirstActivityDate($zohoCustomerId);

        foreach ($this->periodRanges() as $label => [$from, $to]) {
            // See customerFirstActivityDate()'s docblock — a period that
            // ended before this customer's very first transaction (any
            // category) isn't "zero profit", it's "not a customer yet";
            // every field in the row reflects that uniformly. $to is null
            // only for Lifetime, which is unbounded and never predates the
            // customer by definition.
            if ($to !== null && $firstActivity !== null && $to->lt($firstActivity)) {
                $table[$label] = [
                    'Gross Profit' => '—',
                    'Gross Margin %' => '—',
                    'GP Contribution %' => '—',
                    'GP Rank' => '—',
                    'Average GP% per Invoice' => '—',
                ];

                continue;
            }

            $query = ArLedgerEntry::where('zoho_customer_id', $zohoCustomerId)->whereIn('entity_type', ['invoice', 'creditnote'])->whereNotNull('sales_exc_vat');
            $companyQuery = ArLedgerEntry::whereIn('entity_type', ['invoice', 'creditnote'])->whereNotNull('sales_exc_vat');
            $invoiceOnlyQuery = ArLedgerEntry::where('zoho_customer_id', $zohoCustomerId)->where('entity_type', 'invoice')->whereNotNull('sales_exc_vat');
            if ($from !== null) {
                $query->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()]);
                $companyQuery->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()]);
                $invoiceOnlyQuery->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()]);
            }

            $row = (clone $query)->selectRaw('SUM(sales_exc_vat) as sales, SUM(cogs) as cogs')->first();
            $sales = (float) ($row->sales ?? 0);
            $cogs = (float) ($row->cogs ?? 0);
            $gp = $sales - $cogs;
            $companyRow = (clone $companyQuery)->selectRaw('SUM(sales_exc_vat) as sales, SUM(cogs) as cogs')->first();
            $companyGp = (float) ($companyRow->sales ?? 0) - (float) ($companyRow->cogs ?? 0);

            $invoiceMargins = (clone $invoiceOnlyQuery)
                ->select('transaction_number')
                ->selectRaw('SUM(sales_exc_vat) as sales, SUM(gross_profit) as gp')
                ->groupBy('transaction_number')
                ->get()
                ->filter(fn ($r) => (float) $r->sales != 0)
                ->map(fn ($r) => (float) $r->gp / (float) $r->sales * 100)
                ->filter(fn ($m) => $m >= -100);

            $rankInfo = $this->profitabilityRankAndCount($zohoCustomerId, $from, $to);

            $table[$label] = [
                'Gross Profit' => $money($gp),
                'Gross Margin %' => $sales != 0 ? number_format($gp / $sales * 100, 1).'%' : '—',
                // Guarded on this customer's OWN sales existing in the period
                // (same as Gross Margin % above), not just companyGp != 0 —
                // companyGp is company-wide and virtually never zero, so the
                // old guard let a customer with ZERO sales in a period still
                // show "0.00%" (0 ÷ a huge company total) instead of "—".
                // Confirmed live 2026-10-05 (CUS-998): every period after
                // this customer's last real sale showed 0.00% here while
                // Gross Margin % correctly showed "—" right next to it.
                'GP Contribution %' => ($sales != 0 && $companyGp != 0) ? number_format($gp / $companyGp * 100, 2).'%' : '—',
                'GP Rank' => $rankInfo['rank'] !== null ? '#'.$rankInfo['rank'].' / '.number_format($rankInfo['total']) : '—',
                'Average GP% per Invoice' => $invoiceMargins->isNotEmpty() ? number_format($invoiceMargins->avg(), 1).'%' : '—',
            ];
        }

        return $table;
    }

    /**
     * @return array<string, array<int, array{label: string, value: ?float}>>
     */
    private function buildProfitabilityCharts(?string $zohoCustomerId): array
    {
        if ($zohoCustomerId === null) {
            return [];
        }

        $start = Carbon::today()->subMonthsNoOverflow(11)->startOfMonth();
        // Includes credit notes and recomputes gp = sales - cogs, same fix
        // and same reason as buildProfitabilityKpiTable() — the stored
        // gross_profit column is hard-zeroed on every credit note row at
        // the Zoho source, so a reversed invoice's loss/profit used to
        // stay fully counted all month. $invoiceRows below (the per-invoice
        // "Average GP% per Invoice" series) stays invoice-only on purpose —
        // see that method's docblock for why that one metric doesn't mix
        // in credit notes.
        $rows = ArLedgerEntry::where('zoho_customer_id', $zohoCustomerId)
            ->whereIn('entity_type', ['invoice', 'creditnote'])->whereNotNull('sales_exc_vat')
            ->where('transaction_date', '>=', $start->toDateString())
            ->selectRaw('YEAR(transaction_date) as y, MONTH(transaction_date) as m')
            ->selectRaw('SUM(sales_exc_vat) as sales, SUM(cogs) as cogs')
            ->groupBy('y', 'm')->get()
            ->keyBy(fn ($r) => sprintf('%04d-%02d', $r->y, $r->m));

        $invoiceRows = ArLedgerEntry::where('zoho_customer_id', $zohoCustomerId)
            ->where('entity_type', 'invoice')->whereNotNull('sales_exc_vat')
            ->where('transaction_date', '>=', $start->toDateString())
            ->selectRaw('YEAR(transaction_date) as y, MONTH(transaction_date) as m, transaction_number')
            ->selectRaw('SUM(sales_exc_vat) as sales, SUM(gross_profit) as gp')
            ->groupBy('y', 'm', 'transaction_number')->get()
            ->groupBy(fn ($r) => sprintf('%04d-%02d', $r->y, $r->m));

        $series = ['Gross Profit' => [], 'Gross Margin %' => [], 'Average GP% per Invoice' => []];
        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::today()->subMonthsNoOverflow($i)->startOfMonth();
            $row = $rows->get($month->format('Y-m'));
            $sales = (float) ($row->sales ?? 0);
            $gp = $sales - (float) ($row->cogs ?? 0);
            // Gross Profit itself is a real SAR amount — 0 for a no-sales
            // month is accurate, not undefined. The two PERCENTAGE series
            // below are different: dividing by zero sales is undefined, not
            // zero, so a no-sales month gets null (plotted as a gap in the
            // chart, not a misleading drop to 0%) — same fix as GP
            // Contribution %'s table guard above, confirmed live 2026-10-05
            // (CUS-998): every idle month drew the line down to 0% as if
            // margin had collapsed, when really there was just no data.
            $series['Gross Profit'][] = ['label' => $month->format('M'), 'value' => $gp];
            $series['Gross Margin %'][] = ['label' => $month->format('M'), 'value' => $sales != 0 ? round($gp / $sales * 100, 1) : null];

            $margins = $invoiceRows->get($month->format('Y-m'), collect())
                ->filter(fn ($r) => (float) $r->sales != 0)
                ->map(fn ($r) => (float) $r->gp / (float) $r->sales * 100)
                ->filter(fn ($m) => $m >= -100);
            $series['Average GP% per Invoice'][] = ['label' => $month->format('M'), 'value' => $margins->isNotEmpty() ? round($margins->avg(), 1) : null];
        }

        return $series;
    }

    /**
     * Real per-period Collection KPI table, from ar_ledger_entries rows
     * tagged category='COLLECTION' (kpi_amount = the payment amount).
     * Collection Ratio vs Sales and Collection Contribution % compare
     * against the same period's Net Sales / company-wide Collection, same
     * pattern as the Sales tab's Revenue Contribution %. Collection Rank,
     * Volatility % and Average Payment Interval are lifetime-only snapshots
     * passed in already computed (company-wide comparisons, expensive to
     * repeat per period).
     *
     * @return array<string, array<string, string>>
     */
    /**
     * Collection tab KPI table — rebuilt to match the Sales tab's
     * conventions (see buildSalesKpiTable()'s docblock), confirmed live
     * 2026-09-30 against a Zoho reconciliation for CUS-8: Collection
     * Average/Median were wrongly computed as a per-payment average/median
     * (identical to Average Payment Value) instead of a monthly/daily
     * aggregate one — same class of bug as Sales Average before its fix,
     * same fix applies. Volatility/Rank/Average Payment Interval were
     * Lifetime-only externally-computed values; now live per-period.
     * "Ratio vs Sales" used to divide by the old sales_item_performance Net
     * Sales (already proven wrong for the Sales tab); switched to the same
     * ar_ledger_entries Net Sales basis. Payment Count/Average Payment
     * Value/Largest Payment Value used to group rows by transaction_number,
     * which undercounts real payments when Zoho reuses one
     * transaction_number across multiple invoice_payment application rows
     * (confirmed live: 5 real rows collapsed into 2 groups for one
     * customer, 48 vs Zoho's real 51) — now counts every row as its own
     * payment, matching Zoho's own Payment Count exactly.
     */
    private function buildCollectionKpiTable(?string $zohoCustomerId): array
    {
        if ($zohoCustomerId === null) {
            return [];
        }

        $firstCollectionDate = DB::table('ar_ledger_entries')
            ->where('zoho_customer_id', $zohoCustomerId)->where('category', 'COLLECTION')
            ->min('transaction_date');

        $today = Carbon::today();
        $lifetimeStart = $firstCollectionDate ? Carbon::parse($firstCollectionDate)->startOfDay() : $today->copy();

        $ranges = [
            'Lifetime' => [$lifetimeStart, $today, 'month'],
            'Previous Year' => [$today->copy()->subYear()->startOfYear(), $today->copy()->subYear()->endOfYear(), 'month'],
            'YTD' => [$today->copy()->startOfYear(), $today, 'month'],
            'Previous Month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth(), 'day'],
            'MTD' => [$today->copy()->startOfMonth(), $today, 'day'],
            'Yesterday' => [$today->copy()->subDay(), $today->copy()->subDay(), null],
            'Today' => [$today->copy(), $today->copy(), null],
        ];

        $money = fn (float $v) => 'SAR '.number_format($v, 0);
        $table = [];
        $firstActivity = $this->customerFirstActivityDate($zohoCustomerId);

        foreach ($ranges as $label => [$from, $to, $bucket]) {
            // See customerFirstActivityDate()'s docblock — a period that
            // ended before this customer's very first transaction (any
            // category) isn't "zero collected", it's "not a customer yet";
            // every field in the row reflects that uniformly.
            if ($firstActivity !== null && $to->lt($firstActivity)) {
                $table[$label] = [
                    'Collection' => '—',
                    'Collection Ratio vs Sales' => '—',
                    'Collection Average' => '—',
                    'Collection Median' => '—',
                    'Collection Volatility %' => '—',
                    'Collection Contribution %' => '—',
                    'Collection Rank - Company' => '—',
                    'Payment Count' => '—',
                    'Average Payment Value' => '—',
                    'Largest Payment Value' => '—',
                    'Average Payment Interval' => '—',
                ];

                continue;
            }

            // Company-wide Lifetime is unbounded (not this customer's own
            // start date) — same fix and same reason as Sales' Lifetime
            // Contribution %/Rank (see buildSalesKpiTable()).
            $companyFrom = $label === 'Lifetime' ? null : $from;

            $rows = DB::table('ar_ledger_entries')
                ->where('zoho_customer_id', $zohoCustomerId)->where('category', 'COLLECTION')
                ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
                ->pluck('kpi_amount')->map(fn ($v) => (float) $v);

            $collected = $rows->sum();

            // Payment Count/Average/Largest/Interval are scoped to real
            // payments only (customer_payment, invoice_payment) — confirmed
            // live 2026-09-30: a Credit Note Refund (money paid BACK to the
            // customer, not collected) was being counted as a payment,
            // inflating Payment Count and skewing Average Payment Value/
            // Interval. Collection itself ($collected above) stays
            // unfiltered — netting the refund against total collected is
            // correct, only the per-payment stats needed the entity_type
            // filter.
            $paymentRows = DB::table('ar_ledger_entries')
                ->where('zoho_customer_id', $zohoCustomerId)->where('category', 'COLLECTION')
                ->whereIn('entity_type', ['customer_payment', 'invoice_payment'])
                ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
                ->pluck('kpi_amount')->map(fn ($v) => (float) $v);

            $count = $paymentRows->count();
            $largest = $paymentRows->isEmpty() ? null : $paymentRows->max();

            $companyCollected = (float) DB::table('ar_ledger_entries')->where('category', 'COLLECTION')
                ->when($companyFrom !== null, fn ($q) => $q->where('transaction_date', '>=', $companyFrom->toDateString()))
                ->where('transaction_date', '<=', $to->toDateString())
                ->sum('kpi_amount');

            // Lifetime uses the same unbounded $companyFrom as the
            // company-wide figures above — not this customer's own first
            // COLLECTION date (the earlier bug: bounding Net Sales to that
            // date excluded real sales activity that predates this
            // customer's first payment, inflating Collection Ratio vs
            // Sales — confirmed live 2026-09-30, 95.4% vs the real 94.87%
            // for one customer).
            $netSales = (float) DB::table('ar_ledger_entries')
                ->where('zoho_customer_id', $zohoCustomerId)->where('category', 'SALES')
                ->whereIn('entity_type', ['invoice', 'creditnote'])
                ->when($companyFrom !== null, fn ($q) => $q->where('transaction_date', '>=', $companyFrom->toDateString()))
                ->where('transaction_date', '<=', $to->toDateString())
                ->sum('statement_amount');

            $rankInfo = $this->ledgerRankAndCount('COLLECTION', 'kpi_amount', null, $zohoCustomerId, $companyFrom, $to);
            $avgIntervalDays = $this->paymentIntervalDaysForPeriod($zohoCustomerId, $from, $to);

            // Same fix as buildSalesKpiTable()'s $seriesFrom — see that
            // comment (Ahmed Mohsen, 2026-10-04).
            $seriesFrom = $from->lt($lifetimeStart) ? $lifetimeStart->copy() : $from;

            if ($bucket !== null && $seriesFrom->lte($to)) {
                $series = $this->ledgerPeriodSeries($zohoCustomerId, 'COLLECTION', 'kpi_amount', null, $seriesFrom, $to, $bucket);
                $unit = $bucket === 'month' ? '/mo' : '/day';
                $avg = $money((float) $series->avg()).' '.$unit;
                $median = $money($this->medianOf($series)).' '.$unit;
                $volatility = $this->volatilityPercentOf($series);
            } else {
                $avg = '—';
                $median = '—';
                $volatility = '—';
            }

            $table[$label] = [
                'Collection' => $money($collected),
                'Collection Ratio vs Sales' => $netSales > 0 ? number_format($collected / $netSales * 100, 1).'%' : '—',
                'Collection Average' => $avg,
                'Collection Median' => $median,
                'Collection Volatility %' => $volatility,
                'Collection Contribution %' => $companyCollected > 0 ? number_format($collected / $companyCollected * 100, 2).'%' : '—',
                'Collection Rank - Company' => $rankInfo['rank'] !== null ? '#'.$rankInfo['rank'].' / '.number_format($rankInfo['total']) : '—',
                'Payment Count' => number_format($count),
                'Average Payment Value' => $count > 0 ? $money($paymentRows->sum() / $count) : '—',
                'Largest Payment Value' => $largest !== null ? $money($largest) : '—',
                'Average Payment Interval' => $avgIntervalDays !== null ? number_format($avgIntervalDays, 1).' days' : '—',
            ];
        }

        return $table;
    }

    /**
     * Rolling last-12-months monthly totals for the Collection tab's
     * charts. Simplified to the same "no per-invoice-level duplicate
     * series" set as buildSalesCharts() — see that method's docblock.
     *
     * @return array<string, array<int, array{label: string, value: float}>>
     */
    private function buildCollectionCharts(?string $zohoCustomerId): array
    {
        if ($zohoCustomerId === null) {
            return [];
        }

        $start = Carbon::today()->subMonthsNoOverflow(11)->startOfMonth();
        $rows = DB::table('ar_ledger_entries')
            ->where('zoho_customer_id', $zohoCustomerId)->where('category', 'COLLECTION')
            ->where('transaction_date', '>=', $start->toDateString())
            ->selectRaw('YEAR(transaction_date) as y, MONTH(transaction_date) as m')
            ->selectRaw('SUM(kpi_amount) as total')
            ->groupBy('y', 'm')->get()
            ->keyBy(fn ($r) => sprintf('%04d-%02d', $r->y, $r->m));

        // Payment Count/Average Payment Value — real payments only
        // (customer_payment, invoice_payment), same Refund fix as the KPI
        // table above.
        $paymentRows = DB::table('ar_ledger_entries')
            ->where('zoho_customer_id', $zohoCustomerId)->where('category', 'COLLECTION')
            ->whereIn('entity_type', ['customer_payment', 'invoice_payment'])
            ->where('transaction_date', '>=', $start->toDateString())
            ->selectRaw('YEAR(transaction_date) as y, MONTH(transaction_date) as m')
            ->selectRaw('SUM(kpi_amount) as total, COUNT(*) as cnt')
            ->groupBy('y', 'm')->get()
            ->keyBy(fn ($r) => sprintf('%04d-%02d', $r->y, $r->m));

        $series = ['Collection' => [], 'Payment Count' => [], 'Average Payment Value' => []];
        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::today()->subMonthsNoOverflow($i)->startOfMonth();
            $collected = (float) ($rows->get($month->format('Y-m'))->total ?? 0);
            $paymentRow = $paymentRows->get($month->format('Y-m'));
            $paymentTotal = (float) ($paymentRow->total ?? 0);
            $count = (int) ($paymentRow->cnt ?? 0);

            $series['Collection'][] = ['label' => $month->format('M'), 'value' => $collected];
            $series['Payment Count'][] = ['label' => $month->format('M'), 'value' => $count];
            $series['Average Payment Value'][] = ['label' => $month->format('M'), 'value' => $count > 0 ? $paymentTotal / $count : 0];
        }

        return $series;
    }

    /**
     * Zero-filled per-month or per-day amount series from ar_ledger_entries
     * for an arbitrary category — generalizes salesPeriodSeries() for
     * Collection's use of kpi_amount instead of statement_amount (Sales'
     * own series is left untouched to avoid any risk to its already-
     * verified numbers).
     */
    private function ledgerPeriodSeries(string $zohoCustomerId, string $category, string $amountColumn, ?string $entityType, Carbon $from, Carbon $to, string $bucket): \Illuminate\Support\Collection
    {
        $query = DB::table('ar_ledger_entries')
            ->where('zoho_customer_id', $zohoCustomerId)->where('category', $category)
            ->when($entityType !== null, fn ($q) => $q->where('entity_type', $entityType))
            ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()]);

        $series = collect();

        if ($bucket === 'month') {
            $rows = $query->selectRaw('YEAR(transaction_date) as y, MONTH(transaction_date) as m')
                ->selectRaw("SUM({$amountColumn}) as total")
                ->groupBy('y', 'm')->get()
                ->keyBy(fn ($r) => sprintf('%04d-%02d', $r->y, $r->m));

            $cursor = $from->copy()->startOfMonth();
            $end = $to->copy()->startOfMonth();
            while ($cursor->lte($end)) {
                $series->push((float) ($rows->get($cursor->format('Y-m'))->total ?? 0));
                $cursor->addMonthNoOverflow();
            }
        } else {
            $rows = $query->selectRaw('DATE(transaction_date) as d')
                ->selectRaw("SUM({$amountColumn}) as total")
                ->groupBy('d')->get()
                ->keyBy(fn ($r) => (string) $r->d);

            $cursor = $from->copy();
            while ($cursor->lte($to)) {
                $series->push((float) ($rows->get($cursor->toDateString())->total ?? 0));
                $cursor->addDay();
            }
        }

        return $series;
    }

    /**
     * Live per-period company-wide rank + positive-total count, generalized
     * from salesRevenueRankAndCount() for an arbitrary ar_ledger_entries
     * category/column (Sales' own version is left untouched).
     *
     * @return array{rank: ?int, total: int}
     */
    private function ledgerRankAndCount(string $category, string $amountColumn, ?string $entityType, ?string $zohoCustomerId, ?Carbon $from, ?Carbon $to): array
    {
        $totals = DB::table('ar_ledger_entries')
            ->where('category', $category)
            ->when($entityType !== null, fn ($q) => $q->where('entity_type', $entityType))
            ->when($from !== null, fn ($q) => $q->where('transaction_date', '>=', $from->toDateString()))
            ->when($to !== null, fn ($q) => $q->where('transaction_date', '<=', $to->toDateString()))
            ->select('zoho_customer_id')->selectRaw("SUM({$amountColumn}) as total")
            ->groupBy('zoho_customer_id')
            ->havingRaw("SUM({$amountColumn}) > 0")
            ->orderByDesc('total')
            ->pluck('zoho_customer_id')->values();

        $position = $zohoCustomerId !== null ? $totals->search($zohoCustomerId) : false;

        return ['rank' => $position === false ? null : $position + 1, 'total' => $totals->count()];
    }

    /**
     * GP Rank's own version of ledgerRankAndCount() — that generic helper
     * only sums a single stored column, but Gross Profit can't trust the
     * stored gross_profit column at all (see buildProfitabilityKpiTable()'s
     * docblock: hard-zeroed on every credit note row at the Zoho Analytics
     * source, not something fixable from here), so ranking needs
     * SUM(sales_exc_vat) - SUM(cogs) across invoice AND credit note rows
     * per customer instead of a single-column sum. Added 2026-10-05 so
     * company-wide GP Rank nets out reversed invoices the same way the KPI
     * table itself now does, instead of ranking customers on the same
     * inflated/wrong figures the table used to show.
     *
     * @return array{rank: ?int, total: int}
     */
    private function profitabilityRankAndCount(?string $zohoCustomerId, ?Carbon $from, ?Carbon $to): array
    {
        $totals = DB::table('ar_ledger_entries')
            ->where('category', 'SALES')
            ->whereIn('entity_type', ['invoice', 'creditnote'])
            ->when($from !== null, fn ($q) => $q->where('transaction_date', '>=', $from->toDateString()))
            ->when($to !== null, fn ($q) => $q->where('transaction_date', '<=', $to->toDateString()))
            ->select('zoho_customer_id')->selectRaw('SUM(sales_exc_vat) - SUM(cogs) as total')
            ->groupBy('zoho_customer_id')
            ->havingRaw('SUM(sales_exc_vat) - SUM(cogs) > 0')
            ->orderByDesc('total')
            ->pluck('zoho_customer_id')->values();

        $position = $zohoCustomerId !== null ? $totals->search($zohoCustomerId) : false;

        return ['rank' => $position === false ? null : $position + 1, 'total' => $totals->count()];
    }

    /**
     * Credit notes still carrying their own unapplied balance in Zoho — i.e.
     * not yet linked to a specific invoice, so they never show up anywhere
     * in invoice_documents.balance (that table is strictly per-invoice).
     * Confirmed live 2026-10-02 (Ahmed Mohsen, CUS-263): Outstanding AR read
     * 27,230.06 (sum of invoice_documents.balance) vs his reconciled
     * 27,164.48 — the exact 65.58 SAR gap was CN-06842 (18/05/2026), an
     * open credit note with nothing to apply it against. Confirmed
     * company-wide this is narrow and real: only 36 of 9,240 credit-note
     * rows in ar_ledger_entries carry a non-null balance (most are fully
     * applied and correctly already reflected via the invoice they reduced)
     * — those 36 are the only ones double-missed by invoice_documents.
     * Always <= 0 (a credit), so callers just add it to an outstanding sum.
     *
     * Deliberately NOT folding in the separate ar_ledger_entries 'UNAPPLIED'
     * category here — its balance can run positive or negative and its
     * real-world meaning isn't confirmed, unlike this one which Ahmed
     * walked through explicitly.
     *
     * Superseded as the HEADLINE Outstanding Balance adjustment by
     * customerLedgerBalance() below (see its docblock) — kept only for
     * buildDueAgesTable()'s bucket-level breakdown, where it's still exactly
     * right (a real, datable, non-double-counted amount).
     */
    private function unappliedCreditNotesBalance(?string $zohoCustomerId): float
    {
        if ($zohoCustomerId === null) {
            return 0.0;
        }

        return (float) DB::table('ar_ledger_entries')
            ->where('zoho_customer_id', $zohoCustomerId)
            ->where('category', 'SALES')->where('entity_type', 'creditnote')
            ->whereNotNull('balance')
            ->sum('balance');
    }

    /**
     * The customer's true current Accounts Receivable balance — every
     * ar_ledger_entries row for them, net (invoices, credit notes,
     * payments, opening balance, AND manual journal entries). Replaces
     * invoice_documents.balance + unappliedCreditNotesBalance() as the
     * headline Outstanding Balance everywhere it's shown (AR tab, Overview,
     * customer list, DSO/Recommended Credit Limit).
     *
     * Confirmed live 2026-10-02 (Ahmed Mohsen, CUS-39) by pulling the
     * customer's own live Zoho record directly (ZohoContactClient — not a
     * sync, the real-time API): Zoho's own outstanding_receivable_amount
     * was 78,942.05, 6,750 SAR above invoice_documents.balance's 72,192.05
     * — every one of the customer's 213 real invoices matched ours exactly,
     * so the gap wasn't a missing/stale invoice. It was 144 manual journal
     * entries across 81 customers (company-wide total -678,912.22 SAR —
     * real bookkeeping adjustments like balance settlements between related
     * accounts) that invoice_documents structurally cannot hold, because
     * it's sourced purely from Zoho's Invoices endpoint. This customer's
     * ar_ledger_entries net total (78,930.39) landed within 11.66 SAR of
     * Zoho's live figure — exactly this customer's own unused/unapplied
     * credit (which the gross outstanding_receivable_amount field doesn't
     * net out, but a ledger sum naturally does).
     *
     * Tried reconstructing an equivalent total from invoice_documents.balance
     * + unapplied-credit-note balance + journal/opening-balance
     * statement_amount first — it does NOT reconcile (confirmed off by
     * thousands of SAR on real customers): invoice.balance is a NET
     * remaining figure already shaped by every payment/credit applied to it
     * over time, while journal/opening-balance rows only carry a raw
     * statement_amount, not a comparable "remaining" figure — the two can't
     * be safely added together. A straight SUM(statement_amount) across
     * every row for the customer is the one figure guaranteed correct by
     * ordinary ledger math (every signed movement since inception = today's
     * balance), which is exactly what this method does.
     */
    private function customerLedgerBalance(?string $zohoCustomerId): float
    {
        if ($zohoCustomerId === null) {
            return 0.0;
        }

        return (float) DB::table('ar_ledger_entries')
            ->where('zoho_customer_id', $zohoCustomerId)
            ->sum('statement_amount');
    }

    /**
     * Replaces CustomerCreditUsage (a Zoho Analytics "CREDIT LIMIT USAGE"
     * Query Table import) as the source for the Credit Limit tab/Overview's
     * Finance card — confirmed live 2026-10-06 that table's hourly sync
     * wasn't actually reaching new customers: CUS-1526 (added 3 days prior)
     * still had zero rows after a fresh manual sync run, because the
     * upstream Zoho Analytics view itself hadn't included it yet (same
     * architecture problem invoice_documents had, fixed 2026-10-04 by
     * switching Due Ages off it entirely). Both real numbers this needs
     * were already live and accurate elsewhere on this same page —
     * customers.credit_limit (fixed 2026-10-05, ZohoContactSyncJob) and
     * customerLedgerBalance() (used for Outstanding AR everywhere else) —
     * so no new data source is needed, just reusing what's already proven.
     *
     * Credit Status thresholds are NOT invented — reverse-engineered from
     * real historical customer_credit_usage data (confirmed live: exact
     * boundaries at 50%/80%/100%, e.g. 49.32% was always "LOW UTILIZATION",
     * 50.07% was always "MEDIUM", 79.50%/80.03% the same split at 80%,
     * 99.99%/100.00%/100.80% confirming FULLY USED is exactly 100 and
     * anything over is OVER LIMIT) — same label strings the blade's color
     * logic already expects, so no view changes needed beyond the source.
     *
     * Returns a plain object, not a CustomerCreditUsage model — the only
     * fields anything on this page actually reads (confirmed by grep):
     * credit_limit, used_credit, credit_utilization_pct, remaining_credit,
     * available_credit_pct, credit_status.
     */
    /**
     * Either signal alone is enough reason not to recommend fresh credit —
     * confirmed live 2026-10-07 (manager report, CUS-128 and CUS-998, cited
     * as "the same note" despite having opposite Collection histories):
     * Collection Classification "Severe Delay" (last 5 settled invoices took
     * a median of 60+ days to pay) is a real payment-behavior risk on its
     * own even for a customer still actively buying — not conditional on
     * also being "Lost". A Sales Classification containing "Lost" (stopped
     * buying) independently means there's no ongoing relationship a credit
     * limit is even for, regardless of how promptly they paid historically
     * (CUS-998: "On Time / Perfect" collection, "Emerging - Lost" sales — an
     * AND of both conditions would have missed this exact cited example).
     * Previously required both together (AND) — confirmed too narrow the
     * same day it shipped. Credit Status/Recommended Credit Limit both defer
     * to this check first. "Until collection happens", not a permanent flag:
     * it clears itself automatically once Collection/Sales Classification
     * improve on a future recompute.
     */
    private function isHighCreditRisk(?string $collectionClassification, ?string $salesClassification): bool
    {
        return $collectionClassification === 'Severe Delay'
            || ($salesClassification !== null && str_contains($salesClassification, 'Lost'));
    }

    private function buildCreditUsage(Customer $customer, float $outstandingBalance, ?string $collectionClassification, ?string $salesClassification): object
    {
        $creditLimit = (float) ($customer->credit_limit ?? 0);
        $usedCredit = $outstandingBalance;

        if ($creditLimit <= 0) {
            return (object) [
                'credit_limit' => $creditLimit > 0 ? $creditLimit : null,
                'used_credit' => $usedCredit,
                'credit_utilization_pct' => null,
                'remaining_credit' => null,
                'available_credit_pct' => null,
                'credit_status' => 'NO CREDIT LIMIT',
            ];
        }

        $utilizationPct = ($usedCredit / $creditLimit) * 100;
        $remainingCredit = $creditLimit - $usedCredit;
        $availablePct = 100 - $utilizationPct;

        $status = match (true) {
            $utilizationPct < 50 => 'LOW UTILIZATION',
            $utilizationPct < 80 => 'MEDIUM UTILIZATION',
            $utilizationPct < 100 => 'HIGH UTILIZATION',
            $utilizationPct == 100.0 => 'FULLY USED',
            default => 'OVER LIMIT',
        };

        if ($this->isHighCreditRisk($collectionClassification, $salesClassification)) {
            $status = 'BLOCK';
        }

        return (object) [
            'credit_limit' => $creditLimit,
            'used_credit' => $usedCredit,
            'credit_utilization_pct' => round($utilizationPct, 2),
            'remaining_credit' => round($remainingCredit, 2),
            'available_credit_pct' => round($availablePct, 2),
            'credit_status' => $status,
        ];
    }

    /**
     * Average number of days between consecutive distinct Collection
     * transaction dates within a specific period — per-period version of
     * the old Lifetime-only averagePaymentIntervalDays(). Scoped to real
     * payments only (customer_payment, invoice_payment) — same Refund fix
     * as Payment Count above, so a refund date doesn't get counted as a
     * payment date and skew the gaps between real payments.
     */
    private function paymentIntervalDaysForPeriod(string $zohoCustomerId, Carbon $from, Carbon $to): ?float
    {
        $dates = DB::table('ar_ledger_entries')
            ->where('zoho_customer_id', $zohoCustomerId)->where('category', 'COLLECTION')
            ->whereIn('entity_type', ['customer_payment', 'invoice_payment'])
            ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('transaction_date')
            ->distinct()->orderBy('transaction_date')->pluck('transaction_date');

        if ($dates->count() < 2) {
            return null;
        }

        $gaps = [];
        for ($i = 1; $i < $dates->count(); $i++) {
            $gaps[] = Carbon::parse($dates[$i - 1])->diffInDays(Carbon::parse($dates[$i]));
        }

        return array_sum($gaps) / count($gaps);
    }

    /**
     * Return / Sales Orders tabs: sale_items/return_items have no reliable
     * monetary value column (no confirmed price field, just sku/quantity) —
     * only quantity-based KPIs are trustworthy here, unlike Sales/
     * Profitability/Collection above which have real SAR amounts. Matched
     * by customer_name (see the Sales History tab's own docblock for why —
     * these two tables don't have a customer id column yet).
     *
     * @return array<string, array<string, string>>
     */
    /**
     * Matches customer_name exactly, or "<customer_name> <suffix>" —
     * confirmed live (2026-09-30): return_items/sale_items sometimes tag a
     * real customer's transaction rows with a trailing type code (e.g.
     * "SUB", "DEN") that customers.customer_name (synced from Zoho
     * Contacts) never carries, breaking a plain exact-match join even
     * though it's the same account. Checked against Zoho directly first —
     * most other "no match" names turned out to be genuine contact_type
     * "vendor" accounts, correctly excluded elsewhere (see
     * RemoveVendorContacts), not a matching bug — this only widens the
     * match for the real-customer case.
     */
    private function matchCustomerName(\Illuminate\Database\Query\Builder $query, string $customerName): \Illuminate\Database\Query\Builder
    {
        return $query->where(function ($q) use ($customerName) {
            $q->where('customer_name', $customerName)
                ->orWhere('customer_name', 'like', $customerName.' %');
        });
    }

    private function buildQuantityKpiTable(string $table, string $dateColumn, ?string $customerName, string $qtyLabel, string $countLabel): array
    {
        if ($customerName === null || $customerName === '') {
            return [];
        }

        $result = [];
        foreach ($this->periodRanges() as $label => [$from, $to]) {
            $query = $this->matchCustomerName(DB::table($table), $customerName);
            if ($from !== null) {
                $query->whereBetween($dateColumn, [$from->toDateString(), $to->toDateString()]);
            }
            // Excludes 'draft' sale_items rows — Ahmed Mohsen, 2026-10-03,
            // sharing the exact query his team runs in Zoho Analytics for
            // this KPI set, which filters status NOT IN ('draft', 'rejected').
            // Only 'draft' exists in our own status enum (confirmed live:
            // cancelled/completed/confirmed/draft/fulfilled/pending/
            // returned/void — no 'rejected' at all), and it's a real,
            // substantial share of the data: CUS-3 alone has 56 draft order
            // lines totaling 56,118 units, inflating Order Quantity/Count
            // for an order that was never actually placed.
            if ($table === 'sale_items') {
                $query->where('status', '!=', 'draft');
            }

            $result[$label] = [
                $qtyLabel => number_format((int) (clone $query)->sum('quantity')),
                $countLabel => number_format((clone $query)->distinct('document_number')->count('document_number')),
                'SKU Count' => number_format((clone $query)->distinct('sku')->count('sku')),
            ];
        }

        return $result;
    }

    /**
     * inventory_items is the real product catalog (confirmed live
     * 2026-09-26: 6,362 distinct SKUs, stable price per SKU across
     * warehouse/import duplicates, only 238/14,149 rows priced at 0) — the
     * only place in the system with a per-SKU price at all. return_items and
     * sale_items only ever recorded sku/quantity, never a price, so this map
     * is what turns their "Value"-type KPIs from a hard block into a real
     * (labeled-as-estimated) number: quantity × current catalog price,
     * not the exact price on that specific historical transaction (which
     * was never captured anywhere).
     *
     * @return \Illuminate\Support\Collection<string, float>
     */
    private function skuPriceMap(): \Illuminate\Support\Collection
    {
        return DB::table('inventory_items')
            ->select('sku')->selectRaw('AVG(price) as price')
            ->groupBy('sku')
            ->pluck('price', 'sku')
            ->map(fn ($v) => (float) $v);
    }

    /**
     * @param  \Illuminate\Support\Collection<string, float>  $skuPrices
     * @return float estimated SAR value (quantity × catalog price, summed)
     */
    private function estimateValueBySku(\Illuminate\Support\Collection $qtyBySku, \Illuminate\Support\Collection $skuPrices): float
    {
        $total = 0.0;
        foreach ($qtyBySku as $sku => $qty) {
            $total += (float) $qty * (float) ($skuPrices[$sku] ?? 0);
        }

        return $total;
    }

    /**
     * Return tab KPI table — rebuilt from ar_ledger_entries (category=SALES,
     * entity_type=creditnote), same source/reasoning as
     * buildSalesKpiTable(): confirmed live 2026-09-30 — return_items (the
     * old source) only covers 156 of ~1,479 customers (a separate,
     * incomplete manual import; CUS-8 wasn't one of them despite having
     * SAR 990,148.73 in real lifetime returns), while ar_ledger_entries has
     * complete, Zoho-verified credit note data for every customer —
     * confirmed matching Zoho's own report for CUS-8 exactly (175 credit
     * notes, SAR 990,148.73). Quantity/SKU-level fields (old Return
     * Average, SKU Count, Average SKU Revenue) are dropped — ar_ledger has
     * no sku or quantity column, and return_items' partial coverage isn't
     * trustworthy enough to keep as a real KPI (same call as dropping
     * Sales' SKU rows).
     */
    private function buildReturnKpiTable(?string $zohoCustomerId): array
    {
        if ($zohoCustomerId === null) {
            return [];
        }

        // Same Lifetime start as Sales (first invoice/creditnote ever, not
        // just this customer's first credit note) — Return Average/Median
        // must count every month of the business relationship, including
        // ones with zero returns, exactly like Sales Average does. Using
        // the first credit note date instead (an earlier bug) shortened
        // the window and inflated the average (confirmed live 2026-09-30:
        // CUS-8 showed SAR 36,672/mo vs Zoho's real SAR 33,004.96/mo).
        $firstSaleDate = DB::table('ar_ledger_entries')
            ->where('zoho_customer_id', $zohoCustomerId)->where('category', 'SALES')
            ->whereIn('entity_type', ['invoice', 'creditnote'])->min('transaction_date');

        $today = Carbon::today();
        $lifetimeStart = $firstSaleDate ? Carbon::parse($firstSaleDate)->startOfDay() : $today->copy();

        $ranges = [
            'Lifetime' => [$lifetimeStart, $today, 'month'],
            'Previous Year' => [$today->copy()->subYear()->startOfYear(), $today->copy()->subYear()->endOfYear(), 'month'],
            'YTD' => [$today->copy()->startOfYear(), $today, 'month'],
            'Previous Month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth(), 'day'],
            'MTD' => [$today->copy()->startOfMonth(), $today, 'day'],
            'Yesterday' => [$today->copy()->subDay(), $today->copy()->subDay(), null],
            'Today' => [$today->copy(), $today->copy(), null],
        ];

        $money = fn (float $v) => 'SAR '.number_format($v, 0);
        $table = [];

        foreach ($ranges as $label => [$from, $to, $bucket]) {
            $baseQuery = fn () => DB::table('ar_ledger_entries')
                ->where('zoho_customer_id', $zohoCustomerId)->where('category', 'SALES')
                ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()]);

            $returnValue = abs((float) $baseQuery()->where('entity_type', 'creditnote')->sum('statement_amount'));
            $creditNotesCount = $baseQuery()->where('entity_type', 'creditnote')->count();
            $grossSales = (float) $baseQuery()->where('entity_type', 'invoice')->sum('statement_amount');

            // Return Average — REVERTED back to the monthly/daily-aggregate
            // convention (2026-10-03, Ahmed Mohsen): the 2026-09-30 "count-
            // based, same as Average Credit Note" decision got overturned —
            // confirmed with worked numbers that it should divide by the
            // number of months/days in the period (zero-filled, including
            // months with no returns at all), the exact same convention
            // Sales Average and Collection Average already use. e.g.
            // Lifetime: Return Value ÷ (months since first sale + 1) —
            // 191,146.00 ÷ 31 ≈ 6,166.00/mo, not Return Value ÷ Credit Notes
            // Count (which just duplicates Average Credit Note).
            // Same fix as buildSalesKpiTable()'s $seriesFrom — see that
            // comment (Ahmed Mohsen, 2026-10-04): this tab's own
            // $lifetimeStart (first sale date) is reused, same as Sales'.
            $seriesFrom = $from->lt($lifetimeStart) ? $lifetimeStart->copy() : $from;

            if ($bucket !== null && $seriesFrom->lte($to)) {
                $series = $this->ledgerPeriodSeries($zohoCustomerId, 'SALES', 'statement_amount', 'creditnote', $seriesFrom, $to, $bucket)
                    ->map(fn ($v) => abs($v));
                $unit = $bucket === 'month' ? '/mo' : '/day';
                $avg = $money($series->avg() ?? 0).' '.$unit;
                $median = $money($this->medianOf($series)).' '.$unit;
            } else {
                $avg = '—';
                $median = '—';
            }

            $table[$label] = [
                'Return Value' => $returnValue > 0 ? $money($returnValue) : '—',
                'Credit Notes Count' => number_format($creditNotesCount),
                'Return Average' => $avg,
                'Return Median' => $median,
                'Return Rate %' => $grossSales > 0 ? number_format($returnValue / $grossSales * 100, 1).'%' : '—',
                'Average Credit Note' => $creditNotesCount > 0 ? $money($returnValue / $creditNotesCount) : '—',
            ];
        }

        return $table;
    }

    /**
     * Rolling last-12-months monthly totals for the Return tab's charts,
     * from ar_ledger_entries — see buildReturnKpiTable()'s docblock for why
     * the old return_items source was wrong.
     *
     * @return array<string, array<int, array{label: string, value: float}>>
     */
    private function buildReturnCharts(?string $zohoCustomerId): array
    {
        if ($zohoCustomerId === null) {
            return [];
        }

        $start = Carbon::today()->subMonthsNoOverflow(11)->startOfMonth();

        $returnRows = DB::table('ar_ledger_entries')
            ->where('zoho_customer_id', $zohoCustomerId)->where('category', 'SALES')
            ->where('entity_type', 'creditnote')
            ->where('transaction_date', '>=', $start->toDateString())
            ->selectRaw('YEAR(transaction_date) as y, MONTH(transaction_date) as m')
            ->selectRaw('SUM(statement_amount) as total, COUNT(*) as cnt')
            ->groupBy('y', 'm')->get()
            ->keyBy(fn ($r) => sprintf('%04d-%02d', $r->y, $r->m));

        $series = ['Return Value' => [], 'Credit Notes Count' => [], 'Average Credit Note' => []];
        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::today()->subMonthsNoOverflow($i)->startOfMonth();
            $row = $returnRows->get($month->format('Y-m'));
            $value = abs((float) ($row->total ?? 0));
            $cnt = (int) ($row->cnt ?? 0);

            $series['Return Value'][] = ['label' => $month->format('M'), 'value' => $value];
            $series['Credit Notes Count'][] = ['label' => $month->format('M'), 'value' => $cnt];
            $series['Average Credit Note'][] = ['label' => $month->format('M'), 'value' => $cnt > 0 ? round($value / $cnt, 0) : 0];
        }

        return $series;
    }

    /**
     * Sales Orders tab: sale_items.status has real values in production
     * (confirmed live 2026-09-26: pending/completed/confirmed/cancelled/
     * fulfilled/draft/returned/void). "Lost" = cancelled + void (the order
     * didn't convert), "Fulfilled" = completed + confirmed + fulfilled (the
     * order did convert) — a reasonable, not Dr.-Ahmed-confirmed, mapping,
     * same caution as Revenue Volatility %. Value-based KPIs (Sales Order
     * Value, Pending/Lost Value, Value Fulfillment Rate %) are estimated via
     * skuPriceMap() (catalog price × quantity) — see that method's docblock.
     *
     * @param  \Illuminate\Support\Collection<string, float>  $skuPrices
     * @return array<string, array<string, string>>
     */
    private function buildSalesOrdersStatusTable(?string $customerName, \Illuminate\Support\Collection $skuPrices): array
    {
        if ($customerName === null || $customerName === '') {
            return [];
        }

        $lostStatuses = ['cancelled', 'void'];
        $fulfilledStatuses = ['completed', 'confirmed', 'fulfilled'];

        $result = [];
        foreach ($this->periodRanges() as $label => [$from, $to]) {
            // One query per period instead of 8 — group by sku+status here,
            // then do every total/pending/lost/fulfilled/value breakdown in
            // PHP against that single result set. sale_items.customer_name
            // now has an index (added 2026-09-26 after this page 504'd in
            // production under the old per-status query-per-query approach).
            // Excludes 'draft' — see buildQuantityKpiTable()'s comment for why
            // (Ahmed Mohsen, 2026-10-03): a draft order was never actually
            // placed, so it shouldn't count toward Pending/Lost/Fulfillment
            // either.
            $bySkuStatus = DB::table('sale_items')->whereNull('zoho_missing_since')->where('customer_name', $customerName)
                ->where('status', '!=', 'draft')
                ->when($from !== null, fn ($q) => $q->whereBetween('order_date', [$from->toDateString(), $to->toDateString()]))
                ->select('sku', 'status')->selectRaw('SUM(quantity) as qty')
                ->groupBy('sku', 'status')
                ->get();

            $sumQty = fn ($rows) => (int) $rows->sum('qty');
            $countSkus = fn ($rows) => $rows->pluck('sku')->unique()->count();
            $qtyBySku = fn ($rows) => $rows->groupBy('sku')->map(fn ($r) => $r->sum('qty'));

            $pendingRows = $bySkuStatus->where('status', 'pending');
            $lostRows = $bySkuStatus->whereIn('status', $lostStatuses);
            $fulfilledRows = $bySkuStatus->whereIn('status', $fulfilledStatuses);

            $totalQty = $sumQty($bySkuStatus);
            $totalSkus = $countSkus($bySkuStatus);
            $pendingQty = $sumQty($pendingRows);
            $pendingSkus = $countSkus($pendingRows);
            $lostQty = $sumQty($lostRows);
            $lostSkus = $countSkus($lostRows);
            $fulfilledQty = $sumQty($fulfilledRows);
            $fulfilledSkus = $countSkus($fulfilledRows);

            $totalValue = $this->estimateValueBySku($qtyBySku($bySkuStatus), $skuPrices);
            $pendingValue = $this->estimateValueBySku($qtyBySku($pendingRows), $skuPrices);
            $lostValue = $this->estimateValueBySku($qtyBySku($lostRows), $skuPrices);
            $fulfilledValue = $this->estimateValueBySku($qtyBySku($fulfilledRows), $skuPrices);

            $result[$label] = [
                'Sales Order Value' => 'SAR '.number_format($totalValue, 0).' (est.)',
                'Sales Order SKU Count' => number_format($totalSkus),
                'Pending Quantity' => number_format($pendingQty),
                'Pending Value' => 'SAR '.number_format($pendingValue, 0).' (est.)',
                'Pending SKU Count' => number_format($pendingSkus),
                'Pending Rate %' => $totalQty > 0 ? number_format($pendingQty / $totalQty * 100, 1).'%' : '—',
                'Lost Quantity' => number_format($lostQty),
                'Lost Sales Value' => 'SAR '.number_format($lostValue, 0).' (est.)',
                'Lost SKU Count' => number_format($lostSkus),
                'Lost Sales Rate %' => $totalQty > 0 ? number_format($lostQty / $totalQty * 100, 1).'%' : '—',
                'Quantity Fulfillment Rate %' => $totalQty > 0 ? number_format($fulfilledQty / $totalQty * 100, 1).'%' : '—',
                'Value Fulfillment Rate %' => $totalValue > 0 ? number_format($fulfilledValue / $totalValue * 100, 1).'%' : '—',
                'SKU Fulfillment Rate %' => $totalSkus > 0 ? number_format($fulfilledSkus / $totalSkus * 100, 1).'%' : '—',
            ];
        }

        return $result;
    }

    /**
     * @param  \Illuminate\Support\Collection<string, float>  $skuPrices
     * @return array<string, array<int, array{label: string, value: float}>>
     */
    private function buildSalesOrdersCharts(?string $customerName, \Illuminate\Support\Collection $skuPrices): array
    {
        if ($customerName === null || $customerName === '') {
            return [];
        }

        $lostStatuses = ['cancelled', 'void'];
        $fulfilledStatuses = ['completed', 'confirmed', 'fulfilled'];
        $start = Carbon::today()->subMonthsNoOverflow(11)->startOfMonth();

        // Excludes 'draft' on all 3 queries below — same reasoning as
        // buildSalesOrdersStatusTable() (Ahmed Mohsen, 2026-10-03): these
        // charts' "total" is also the Fulfillment Rate % denominator, so a
        // draft order would both inflate Sales Order Value/SKU Count and
        // understate Fulfillment Rate %.
        $rows = DB::table('sale_items')->whereNull('zoho_missing_since')->where('customer_name', $customerName)
            ->where('status', '!=', 'draft')
            ->where('order_date', '>=', $start->toDateString())
            ->selectRaw('YEAR(order_date) as y, MONTH(order_date) as m, status')
            ->selectRaw('SUM(quantity) as qty')
            ->groupBy('y', 'm', 'status')->get()
            ->groupBy(fn ($r) => sprintf('%04d-%02d', $r->y, $r->m));

        // Distinct SKUs need their own query per month/status — COUNT(DISTINCT
        // sku) can't be derived from the quantity-grouped rows above.
        $skuRows = DB::table('sale_items')->whereNull('zoho_missing_since')->where('customer_name', $customerName)
            ->where('status', '!=', 'draft')
            ->where('order_date', '>=', $start->toDateString())
            ->selectRaw('YEAR(order_date) as y, MONTH(order_date) as m, status, sku')
            ->distinct()
            ->get()
            ->groupBy(fn ($r) => sprintf('%04d-%02d', $r->y, $r->m));

        // Per-sku quantity (not just per-status total) needed to estimate
        // monetary value via skuPriceMap() — a separate grouping from $rows
        // above since price varies by sku, not by status.
        $qtyBySkuRows = DB::table('sale_items')->whereNull('zoho_missing_since')->where('customer_name', $customerName)
            ->where('status', '!=', 'draft')
            ->where('order_date', '>=', $start->toDateString())
            ->selectRaw('YEAR(order_date) as y, MONTH(order_date) as m, status, sku')
            ->selectRaw('SUM(quantity) as qty')
            ->groupBy('y', 'm', 'status', 'sku')->get()
            ->groupBy(fn ($r) => sprintf('%04d-%02d', $r->y, $r->m));

        $series = [
            'Sales Order Value' => [], 'Sales Order SKU Count' => [],
            'Lost Sales Value' => [], 'Lost SKU Count' => [],
            'Quantity Fulfillment Rate %' => [], 'Value Fulfillment Rate %' => [], 'SKU Fulfillment Rate %' => [],
            'Lost Sales Rate %' => [],
        ];
        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::today()->subMonthsNoOverflow($i)->startOfMonth();
            $monthRows = $rows->get($month->format('Y-m'), collect());
            $monthSkuRows = $skuRows->get($month->format('Y-m'), collect());
            $monthQtyBySku = $qtyBySkuRows->get($month->format('Y-m'), collect());
            $total = (float) $monthRows->sum('qty');
            $fulfilled = (float) $monthRows->whereIn('status', $fulfilledStatuses)->sum('qty');
            $lost = (float) $monthRows->whereIn('status', $lostStatuses)->sum('qty');

            $totalSkus = $monthSkuRows->pluck('sku')->unique()->count();
            $fulfilledSkus = $monthSkuRows->whereIn('status', $fulfilledStatuses)->pluck('sku')->unique()->count();
            $lostSkus = $monthSkuRows->whereIn('status', $lostStatuses)->pluck('sku')->unique()->count();

            // groupBy('sku')->sum() rather than pluck() — a sku can appear
            // under more than one status in the same month, and pluck()
            // would silently overwrite instead of summing those rows.
            $sumBySku = fn ($rows) => $rows->groupBy('sku')->map(fn ($r) => $r->sum('qty'));
            $totalValue = $this->estimateValueBySku($sumBySku($monthQtyBySku), $skuPrices);
            $fulfilledValue = $this->estimateValueBySku($sumBySku($monthQtyBySku->whereIn('status', $fulfilledStatuses)), $skuPrices);
            $lostValue = $this->estimateValueBySku($sumBySku($monthQtyBySku->whereIn('status', $lostStatuses)), $skuPrices);

            $series['Sales Order Value'][] = ['label' => $month->format('M'), 'value' => round($totalValue, 0)];
            $series['Sales Order SKU Count'][] = ['label' => $month->format('M'), 'value' => $totalSkus];
            $series['Lost Sales Value'][] = ['label' => $month->format('M'), 'value' => round($lostValue, 0)];
            $series['Lost SKU Count'][] = ['label' => $month->format('M'), 'value' => $lostSkus];
            $series['Quantity Fulfillment Rate %'][] = ['label' => $month->format('M'), 'value' => $total > 0 ? round($fulfilled / $total * 100, 1) : 0];
            $series['Value Fulfillment Rate %'][] = ['label' => $month->format('M'), 'value' => $totalValue > 0 ? round($fulfilledValue / $totalValue * 100, 1) : 0];
            $series['SKU Fulfillment Rate %'][] = ['label' => $month->format('M'), 'value' => $totalSkus > 0 ? round($fulfilledSkus / $totalSkus * 100, 1) : 0];
            $series['Lost Sales Rate %'][] = ['label' => $month->format('M'), 'value' => $total > 0 ? round($lost / $total * 100, 1) : 0];
        }

        return $series;
    }

    /**
     * Overview tab's target-achievement / YoY-growth block. Sourced from
     * customers.extra (annual_sales_tgt/annual_collection_tgt and the 12
     * monthly_*_targets, all real synced fields — see
     * ImportCustomersAnalyticsReport::buildExtra()). Confirmed live
     * (2026-09-26): only 1 of 1,473 customers actually has a non-zero
     * annual target set today, so most customers will legitimately show
     * "—" here — that's a real data-completeness gap on the targets
     * themselves, not a bug in this calculation. YTD target = sum of each
     * elapsed month's target (falls back to annual/12 × months-elapsed if
     * no monthly breakdown is set); MTD target = the current month's own
     * target (falls back to annual/12). YoY Growth % compares this year's
     * YTD Net Sales to the same Jan 1–today window last year.
     *
     * @return array<string, ?string>
     */
    private function buildOverviewTargets(?string $zohoCustomerId, array $customerExtra): array
    {
        if ($zohoCustomerId === null) {
            return [];
        }

        $today = Carbon::today();
        $months = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
        $currentMonthIndex = (int) $today->format('n');
        $currentMonthAbbrev = $months[$currentMonthIndex - 1];

        // Net Sales (Invoices − Credit Notes), not invoice-only — Credit Note
        // rows already carry a negative statement_amount, so unioning both
        // types and summing does the subtraction. ar_ledger_entries, not
        // sales_item_performance — confirmed live 2026-09-30: this block was
        // still on the old table (already proven to not match Zoho for the
        // Sales tab), which is exactly why YoY Growth % didn't match a
        // manual reconciliation (-20.5% here vs the real -19.67%).
        $ytdSales = (float) DB::table('ar_ledger_entries')->where('zoho_customer_id', $zohoCustomerId)
            ->where('category', 'SALES')->whereIn('entity_type', ['invoice', 'creditnote'])
            ->whereBetween('transaction_date', [$today->copy()->startOfYear()->toDateString(), $today->toDateString()])
            ->sum('statement_amount');
        $mtdSales = (float) DB::table('ar_ledger_entries')->where('zoho_customer_id', $zohoCustomerId)
            ->where('category', 'SALES')->whereIn('entity_type', ['invoice', 'creditnote'])
            ->whereBetween('transaction_date', [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()])
            ->sum('statement_amount');
        $ytdCollection = (float) ArLedgerEntry::where('zoho_customer_id', $zohoCustomerId)
            ->where('category', 'COLLECTION')
            ->whereBetween('transaction_date', [$today->copy()->startOfYear()->toDateString(), $today->toDateString()])
            ->sum('kpi_amount');
        $mtdCollection = (float) ArLedgerEntry::where('zoho_customer_id', $zohoCustomerId)
            ->where('category', 'COLLECTION')
            ->whereBetween('transaction_date', [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()])
            ->sum('kpi_amount');

        $monthlySalesTgts = $customerExtra['monthly_sales_targets'] ?? [];
        $monthlyCollectionTgts = $customerExtra['monthly_collection_targets'] ?? [];
        $annualSalesTgt = (float) ($customerExtra['annual_sales_tgt'] ?? 0);
        $annualCollectionTgt = (float) ($customerExtra['annual_collection_tgt'] ?? 0);

        $ytdSalesTarget = 0.0;
        $ytdCollectionTarget = 0.0;
        for ($i = 0; $i < $currentMonthIndex; $i++) {
            $ytdSalesTarget += (float) ($monthlySalesTgts[$months[$i]] ?? 0);
            $ytdCollectionTarget += (float) ($monthlyCollectionTgts[$months[$i]] ?? 0);
        }
        $mtdSalesTarget = (float) ($monthlySalesTgts[$currentMonthAbbrev] ?? 0);
        $mtdCollectionTarget = (float) ($monthlyCollectionTgts[$currentMonthAbbrev] ?? 0);

        if ($ytdSalesTarget <= 0 && $annualSalesTgt > 0) {
            $ytdSalesTarget = $annualSalesTgt / 12 * $currentMonthIndex;
        }
        if ($mtdSalesTarget <= 0 && $annualSalesTgt > 0) {
            $mtdSalesTarget = $annualSalesTgt / 12;
        }
        if ($ytdCollectionTarget <= 0 && $annualCollectionTgt > 0) {
            $ytdCollectionTarget = $annualCollectionTgt / 12 * $currentMonthIndex;
        }
        if ($mtdCollectionTarget <= 0 && $annualCollectionTgt > 0) {
            $mtdCollectionTarget = $annualCollectionTgt / 12;
        }

        // subYearNoOverflow (not subYear) — today=Feb 29 would otherwise
        // roll to Mar 1 last year instead of clamping to Feb 28, per Ahmed's
        // review of the edge cases.
        $lastYearYtdSales = (float) DB::table('ar_ledger_entries')->where('zoho_customer_id', $zohoCustomerId)
            ->where('category', 'SALES')->whereIn('entity_type', ['invoice', 'creditnote'])
            ->whereBetween('transaction_date', [$today->copy()->subYearNoOverflow()->startOfYear()->toDateString(), $today->copy()->subYearNoOverflow()->toDateString()])
            ->sum('statement_amount');

        return [
            'ytd_sales_tgt_ach_pct' => $ytdSalesTarget > 0 ? number_format($ytdSales / $ytdSalesTarget * 100, 1).'%' : null,
            'mtd_sales_tgt_ach_pct' => $mtdSalesTarget > 0 ? number_format($mtdSales / $mtdSalesTarget * 100, 1).'%' : null,
            'ytd_collection_tgt_ach_pct' => $ytdCollectionTarget > 0 ? number_format($ytdCollection / $ytdCollectionTarget * 100, 1).'%' : null,
            'mtd_collection_tgt_ach_pct' => $mtdCollectionTarget > 0 ? number_format($mtdCollection / $mtdCollectionTarget * 100, 1).'%' : null,
            // $ytdSales <= 0 (a return outweighing any sale, or no sale at
            // all) against a positive prior-year base produces a raw
            // percentage past -100% (e.g. -302.17 vs 102,280.51 YTD last
            // year = "-100.3%"), which reads as nonsense — you can't sell
            // less than nothing. Confirmed live 2026-10-06 (manager report,
            // CUS-128): the underlying math wasn't wrong, the raw number
            // just has no sensible real-world meaning past -100%, so that
            // case gets the plain, bounded label instead.
            'yoy_growth_pct' => $lastYearYtdSales > 0
                ? ($ytdSales > 0 ? number_format(($ytdSales - $lastYearYtdSales) / $lastYearYtdSales * 100, 1).'%' : '-100% (Lost)')
                : null,
        ];
    }

    /**
     * Accounts Receivable tab, in the same period-table shape as
     * Sales/Collection/Profitability. AR balance itself is a running total,
     * not a dated transaction — there's no historical "balance as of the end
     * of last month" snapshot anywhere in this system, so a period column
     * can't mean "what the balance was back then" without fabricating
     * numbers. Instead, each period buckets invoices by their own
     * invoice_date (same convention as every other tab) and reports their
     * CURRENT balance — e.g. "Outstanding AR — Previous Month" is the
     * balance still unpaid today from invoices issued last month. Real and
     * non-fabricated, just a different (and honestly labeled) definition of
     * "period" than a true historical snapshot would be.
     *
     * @return array<string, array<string, string>>
     */
    private function buildArKpiTable(?string $zohoCustomerId, float $outstandingBalance): array
    {
        if ($zohoCustomerId === null) {
            return [];
        }

        $today = Carbon::today();
        $table = [];

        foreach ($this->periodRanges() as $label => [$from, $to]) {
            // Excludes 'draft' — see buildArKpiTable's caller (show()) for
            // the full story on why draft invoices can't count as real AR.
            $docs = InvoiceDocument::where('zoho_customer_id', $zohoCustomerId)->where('status', '!=', 'draft');
            $companyDocs = DB::table('invoice_documents')->where('status', '!=', 'draft');
            if ($from !== null) {
                $docs->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()]);
                $companyDocs->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()]);
            }

            // Lifetime reuses the already-corrected total passed in from show()
            // (includes unappliedCreditNotesBalance()) rather than recomputing
            // from invoice_documents alone — see that method's docblock. Other
            // periods keep the invoice-only figure: unapplied credit notes
            // aren't tied to a specific invoice_date, so they can't be bucketed
            // the same way, and only Lifetime is actually rendered on the AR
            // tab today (see show.blade.php's array_intersect_key).
            $outstanding = $from === null ? $outstandingBalance : (float) (clone $docs)->sum('balance');
            $outstandingCount = (clone $docs)->where('balance', '>', 0)->count();
            $companyOutstanding = (float) (clone $companyDocs)->sum('balance');

            $overdueAmount = (float) (clone $docs)->where('balance', '>', 0)->where('due_date', '<', $today->toDateString())->sum('balance');
            $overdueCount = (clone $docs)->where('balance', '>', 0)->where('due_date', '<', $today->toDateString())->count();
            $companyOverdue = (float) (clone $companyDocs)->where('balance', '>', 0)->where('due_date', '<', $today->toDateString())->sum('balance');

            // Cumulative, not just this-month's-due-dates — manager feedback
            // (Ahmed Mohsen, 2026-09-30): a rep opening a customer needs to
            // know everything owed by end of this month, including balances
            // already overdue from earlier months, not only invoices whose
            // own due_date happens to fall inside the current month.
            $dueThisMonth = (float) (clone $docs)->where('balance', '>', 0)
                ->where('due_date', '<=', $today->copy()->endOfMonth()->toDateString())
                ->sum('balance');

            $table[$label] = [
                'Outstanding AR' => 'SAR '.number_format($outstanding, 0),
                'Outstanding Invoices Count' => number_format($outstandingCount),
                'Outstanding Contribution % (Company)' => $companyOutstanding > 0 ? number_format($outstanding / $companyOutstanding * 100, 2).'%' : '—',
                'Overdue Amount' => 'SAR '.number_format($overdueAmount, 0),
                'Overdue Invoices Count' => number_format($overdueCount),
                'Overdue Ratio %' => $outstanding > 0 ? number_format($overdueAmount / $outstanding * 100, 1).'%' : '—',
                'Overdue Contribution % (Company)' => $companyOverdue > 0 ? number_format($overdueAmount / $companyOverdue * 100, 2).'%' : '—',
                'Due This Month' => 'SAR '.number_format($dueThisMonth, 0),
                'Due This Month Ratio %' => $outstanding > 0 ? number_format($dueThisMonth / $outstanding * 100, 1).'%' : '—',
            ];
        }

        return $table;
    }

    /**
     * The 4 current-state donut snapshots shown on the AR tab (Outstanding
     * Contribution %, Overdue Ratio %, Overdue Contribution %, Due This
     * Month Ratio %) — always "right now," independent of the period table
     * above, same convention as the Credit Utilisation donut.
     *
     * @return array<string, ?float>
     */
    private function buildArSnapshot(?string $zohoCustomerId, float $outstandingBalance): array
    {
        if ($zohoCustomerId === null) {
            return [];
        }

        $today = Carbon::today();
        $docs = InvoiceDocument::where('zoho_customer_id', $zohoCustomerId)->where('status', '!=', 'draft');

        $overdueAmount = (float) (clone $docs)->where('balance', '>', 0)->where('due_date', '<', $today->toDateString())->sum('balance');
        // Cumulative through end of this month — see the period table's
        // Due This Month for why (Ahmed Mohsen, 2026-09-30).
        $dueThisMonth = (float) (clone $docs)->where('balance', '>', 0)
            ->where('due_date', '<=', $today->copy()->endOfMonth()->toDateString())
            ->sum('balance');

        $companyOutstanding = (float) DB::table('invoice_documents')->where('status', '!=', 'draft')->sum('balance');
        $companyOverdue = (float) DB::table('invoice_documents')->where('status', '!=', 'draft')->where('balance', '>', 0)->where('due_date', '<', $today->toDateString())->sum('balance');

        return [
            'outstanding_contribution_pct' => $companyOutstanding > 0 ? round($outstandingBalance / $companyOutstanding * 100, 2) : null,
            'overdue_ratio_pct' => $outstandingBalance > 0 ? round($overdueAmount / $outstandingBalance * 100, 1) : null,
            'overdue_contribution_pct' => $companyOverdue > 0 ? round($overdueAmount / $companyOverdue * 100, 2) : null,
            'due_this_month_ratio_pct' => $outstandingBalance > 0 ? round($dueThisMonth / $outstandingBalance * 100, 1) : null,
        ];
    }

    /**
     * The Excel spec's exact 8 aging buckets (KPIS 10-2026.xlsx, CUSTOMER
     * sheet rows 89-96: 0-30/31-60/61-90/91-120/121-150/151-180/181-210/
     * >210). Neither of our two pre-computed taxonomy fields
     * (ar_ledger_entries.balance_aging, invoice_documents.age_tier) go past
     * 180 days — both collapse everything older into one "over 180/181"
     * bucket, confirmed live 2026-09-27 (7 buckets max in both). Computing
     * the bucket ourselves from invoice_documents.age_in_days (a real
     * per-invoice day count, range confirmed 0–896) is what makes the full
     * 8-bucket spec possible — this is genuinely finer-grained real data,
     * not an invented split.
     */
    private function ageDaysToBucket(int $days): string
    {
        return match (true) {
            $days <= 30 => 'Aging 0-30',
            $days <= 60 => 'Aging 31-60',
            $days <= 90 => 'Aging 61-90',
            $days <= 120 => 'Aging 91-120',
            $days <= 150 => 'Aging 121-150',
            $days <= 180 => 'Aging 151-180',
            $days <= 210 => 'Aging 181-210',
            default => 'Aging >210',
        };
    }

    /**
     * Due Ages tab's main table — rebuilt to source from
     * ar_ledger_entries.balance_aging directly (invoice rows + unapplied
     * credit notes with a non-null, non-zero balance), not recomputed live
     * from invoice_documents. Confirmed wrong live 2026-10-04 (Ahmed Mohsen,
     * CUS-1184): invoice_documents lagged this one customer's recent
     * activity in 3 separate ways at once — 2 invoices still marked 'draft'
     * that Zoho had already sent, 2 invoices missing from the table
     * entirely, and 6 invoices carrying a stale pre-payment balance — none
     * of them journal entries or opening balance despite that being this
     * method's own prior explanation. Together they explained the exact
     * 2,597.96 SAR gap against the Credit Limit tab's (ar_ledger-sourced)
     * figure. ar_ledger_entries.balance_aging is Zoho's own pre-computed
     * bucket for every row (confirmed live: 0 of 6,123 real open-invoice
     * rows company-wide have a null value here), so this both fixes the
     * staleness and guarantees the footer always equals the AR tab's
     * Outstanding AR — same source now, not two tables that can drift.
     *
     * Trade-off accepted: Zoho's own taxonomy only goes to 7 buckets
     * (>180 is its own ceiling, same limit invoice_documents.age_tier also
     * had), one fewer than the Excel spec's 8-bucket split at >210.
     * Correctness against the ledger wins over that extra split, which
     * wasn't reliably available from any real data source anyway.
     *
     * @return \Illuminate\Support\Collection<int, object{balance_aging: string, total: float, count: int}>
     */
    private function buildDueAgesTable(?string $zohoCustomerId): \Illuminate\Support\Collection
    {
        $buckets = ['Aging 0-30', 'Aging 31-60', 'Aging 61-90', 'Aging 91-120', 'Aging 121-150', 'Aging 151-180', 'Aging >180'];

        if ($zohoCustomerId === null) {
            return collect($buckets)->map(fn ($b) => (object) ['balance_aging' => $b, 'total' => 0.0, 'count' => 0]);
        }

        $totals = array_fill_keys($buckets, 0.0);
        $counts = array_fill_keys($buckets, 0);

        DB::table('ar_ledger_entries')
            ->where('zoho_customer_id', $zohoCustomerId)
            ->where('category', 'SALES')->whereIn('entity_type', ['invoice', 'creditnote'])
            ->whereNotNull('balance')->where('balance', '!=', 0)
            ->whereNotNull('balance_aging')
            ->select('entity_type', 'balance', 'balance_aging')
            ->get()
            ->each(function ($row) use (&$totals, &$counts) {
                $bucket = $this->ledgerAgingLabel($row->balance_aging);
                if ($bucket === null) {
                    return;
                }
                $totals[$bucket] += (float) $row->balance;
                if ($row->entity_type === 'invoice') {
                    $counts[$bucket]++;
                }
            });

        return collect($buckets)->map(fn ($b) => (object) ['balance_aging' => $b, 'total' => $totals[$b], 'count' => $counts[$b]]);
    }

    /**
     * Maps ar_ledger_entries' own "N. low-high" bucket text (e.g.
     * "1. 0-30", "7. >180") onto this page's plain bucket labels — by
     * substring rather than an exact map, since the leading "N. " ordinal
     * prefix isn't otherwise useful and is one more thing to keep in sync.
     * Returns null for a value that matches none of the 7 known buckets
     * (shouldn't happen given 0/6,123 nulls confirmed live, but a silently
     * dropped row is safer than a wrongly bucketed one).
     */
    private function ledgerAgingLabel(string $zohoBucket): ?string
    {
        return match (true) {
            str_contains($zohoBucket, '0-30') => 'Aging 0-30',
            str_contains($zohoBucket, '31-60') => 'Aging 31-60',
            str_contains($zohoBucket, '61-90') => 'Aging 61-90',
            str_contains($zohoBucket, '91-120') => 'Aging 91-120',
            str_contains($zohoBucket, '121-150') => 'Aging 121-150',
            str_contains($zohoBucket, '151-180') => 'Aging 151-180',
            str_contains($zohoBucket, '>180') => 'Aging >180',
            default => null,
        };
    }

    /**
     * Due Ages tab's 12-month stacked composition chart, same "honest
     * redefinition of month" convention as buildArKpiTable(): there's no
     * historical snapshot of what the aging distribution looked like N
     * months ago, so each month bucket is by invoice_date, and every
     * bucket's amount is that invoice's CURRENT balance/age — "of the
     * invoices issued in month X, how much is still outstanding today and
     * in which of the 8 real aging buckets." Each month's bar is normalized
     * to its own 100% (composition, not absolute volume — matches the
     * requested mockup style).
     *
     * @return array{buckets: array<int, string>, colors: array<string, string>, months: array<int, array{label: string, shares: array<string, float>}>}
     */
    private function buildDueAgesChart(?string $zohoCustomerId): array
    {
        $buckets = ['Aging 0-30', 'Aging 31-60', 'Aging 61-90', 'Aging 91-120', 'Aging 121-150', 'Aging 151-180', 'Aging 181-210', 'Aging >210'];
        $palette = ['#2f7de1', '#1c7f78', '#71b4d6', '#139653', '#c9a227', '#e08a2e', '#e0632e', '#ad4056'];
        $colors = array_combine($buckets, $palette);

        if ($zohoCustomerId === null) {
            return ['buckets' => $buckets, 'colors' => $colors, 'months' => []];
        }

        $today = Carbon::today();
        $start = $today->copy()->subMonthsNoOverflow(11)->startOfMonth();

        $rows = InvoiceDocument::where('zoho_customer_id', $zohoCustomerId)
            ->where('status', '!=', 'draft')
            ->where('balance', '>', 0)
            ->whereNotNull('invoice_date')
            ->where('invoice_date', '>=', $start->toDateString())
            ->select('balance', 'invoice_date')
            ->get()
            ->groupBy(fn ($doc) => Carbon::parse($doc->invoice_date)->format('Y-m'));

        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = $today->copy()->subMonthsNoOverflow($i)->startOfMonth();
            $monthDocs = $rows->get($month->format('Y-m'), collect());

            $bucketTotals = array_fill_keys($buckets, 0.0);
            foreach ($monthDocs as $doc) {
                $days = Carbon::parse($doc->invoice_date)->diffInDays($today);
                $bucketTotals[$this->ageDaysToBucket($days)] += (float) $doc->balance;
            }
            $monthTotal = array_sum($bucketTotals);

            $shares = [];
            foreach ($buckets as $bucket) {
                $shares[$bucket] = $monthTotal > 0 ? round($bucketTotals[$bucket] / $monthTotal * 100, 1) : 0.0;
            }

            $months[] = ['label' => $month->format('M'), 'shares' => $shares];
        }

        return ['buckets' => $buckets, 'colors' => $colors, 'months' => $months];
    }

    /**
     * Item Performance tab. Per Dr. Ahmed's voice note (2026-09-27): "Owner"
     * = the Purchaser responsible for an item's SKUs (item_owners.owner,
     * imported from a Zoho Analytics "Items" export — see that table's
     * migration), joined to sales_item_performance by zoho_item_id
     * (confirmed live 2026-09-27: 100% of this customer base's invoice line
     * items matched an owner). Owners are ranked by this customer's own
     * Lifetime Net Sales — not a fixed "Owner 1..5" roster, since different
     * customers genuinely buy from different numbers of owners (there are
     * only 8 owners system-wide total). "Contribution %" here means this
     * owner's share of THIS CUSTOMER's total (not company-wide) — the
     * natural reading for a per-customer owner breakdown.
     *
     * Built as 8 queries total (7 periods + 1 rolling-12-months for charts),
     * each fetching raw rows once and aggregating in PHP — same
     * query-count lesson as buildSalesOrdersStatusTable's 2026-09-26 rework
     * (avoids N queries × number-of-owners).
     *
     * @return array<int, array{rank: int, label: string, name: string, table: array<string, array<string, string>>, charts: array<string, array<int, array{label: string, value: float}>>}>
     */
    private function buildItemPerformanceData(?string $zohoCustomerId): array
    {
        if ($zohoCustomerId === null) {
            return [];
        }

        $money = fn (float $v) => 'SAR '.number_format($v, 0);
        $baseQuery = fn () => DB::table('sales_item_performance as sip')
            ->join('item_owners as io', 'io.zoho_item_id', '=', 'sip.zoho_item_id')
            ->where('sip.zoho_customer_id', $zohoCustomerId)
            ->where('sip.transaction_type', 'Invoice')
            ->select('io.owner', 'sip.transaction_number', 'sip.zoho_item_id')
            ->selectRaw('sip.item_total as item_total, sip.item_gross_profit as item_gross_profit');

        $rowsByPeriod = [];
        foreach ($this->periodRanges() as $label => [$from, $to]) {
            $rowsByPeriod[$label] = $baseQuery()
                ->when($from !== null, fn ($q) => $q->whereBetween('sip.transaction_date', [$from->toDateString(), $to->toDateString()]))
                ->get();
        }

        // Rank owners by Lifetime Net Sales — this defines both the tab
        // order and which owners exist at all for this customer.
        $ranking = $rowsByPeriod['Lifetime']->groupBy('owner')
            ->map(fn ($rows) => (float) $rows->sum('item_total'))
            ->sortDesc();

        if ($ranking->isEmpty()) {
            return [];
        }

        $ownerStats = fn (Collection $rows) => [
            'net_sales' => (float) $rows->sum('item_total'),
            'gross_profit' => (float) $rows->sum('item_gross_profit'),
            'invoice_count' => $rows->pluck('transaction_number')->unique()->count(),
            'sku_count' => $rows->pluck('zoho_item_id')->unique()->count(),
        ];

        $owners = [];
        $rank = 0;
        foreach ($ranking->keys() as $ownerName) {
            $rank++;
            $table = [];

            foreach ($rowsByPeriod as $label => $periodRows) {
                $ownerRows = $periodRows->where('owner', $ownerName);
                $stat = $ownerStats($ownerRows);
                $customerTotalNet = (float) $periodRows->sum('item_total');
                $customerTotalGp = (float) $periodRows->sum('item_gross_profit');

                $table[$label] = [
                    'Net Sales' => $money($stat['net_sales']),
                    'Avg Sales' => $stat['invoice_count'] > 0 ? $money($stat['net_sales'] / $stat['invoice_count']) : '—',
                    'Sales Contribution %' => $customerTotalNet > 0 ? number_format($stat['net_sales'] / $customerTotalNet * 100, 1).'%' : '—',
                    'SKU Count' => number_format($stat['sku_count']),
                    'SKU Avg Sales' => $stat['sku_count'] > 0 ? $money($stat['net_sales'] / $stat['sku_count']) : '—',
                    'Profit %' => $stat['net_sales'] != 0 ? number_format($stat['gross_profit'] / $stat['net_sales'] * 100, 1).'%' : '—',
                    'Profit Contribution %' => $customerTotalGp != 0 ? number_format($stat['gross_profit'] / $customerTotalGp * 100, 1).'%' : '—',
                ];
            }

            $owners[] = [
                'rank' => $rank,
                'label' => "Owner {$rank}",
                'name' => (string) $ownerName,
                'table' => $table,
                'charts' => [], // filled below, once, for every owner together
            ];
        }

        $ownerNames = $ranking->keys()->all();
        $charts = $this->buildItemPerformanceCharts($zohoCustomerId, $ownerNames);
        foreach ($owners as &$owner) {
            $owner['charts'] = $charts[$owner['name']] ?? [];
        }
        unset($owner);

        return $owners;
    }

    /**
     * @param  array<int, string>  $ownerNames  every owner to build a full (zero-filled) 12-month series for
     * @return array<string, array<string, array<int, array{label: string, value: float}>>> ownerName => metric => points
     */
    private function buildItemPerformanceCharts(string $zohoCustomerId, array $ownerNames): array
    {
        $start = Carbon::today()->subMonthsNoOverflow(11)->startOfMonth();

        $rows = DB::table('sales_item_performance as sip')
            ->join('item_owners as io', 'io.zoho_item_id', '=', 'sip.zoho_item_id')
            ->where('sip.zoho_customer_id', $zohoCustomerId)
            ->where('sip.transaction_type', 'Invoice')
            ->where('sip.transaction_date', '>=', $start->toDateString())
            ->selectRaw('io.owner, YEAR(sip.transaction_date) as y, MONTH(sip.transaction_date) as m, sip.transaction_number, sip.zoho_item_id, sip.item_total, sip.item_gross_profit')
            ->get()
            ->groupBy(fn ($r) => sprintf('%04d-%02d', $r->y, $r->m));

        $metrics = ['Net Sales', 'Avg Sales', 'Sales Contribution %', 'SKU Count', 'SKU Avg Sales', 'Profit %', 'Profit Contribution %'];
        $charts = [];
        foreach ($ownerNames as $ownerName) {
            $charts[$ownerName] = array_fill_keys($metrics, []);
        }

        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::today()->subMonthsNoOverflow($i)->startOfMonth();
            $monthRows = $rows->get($month->format('Y-m'), collect());
            $customerTotalNet = (float) $monthRows->sum('item_total');
            $customerTotalGp = (float) $monthRows->sum('item_gross_profit');
            $byOwner = $monthRows->groupBy('owner');

            foreach ($ownerNames as $ownerName) {
                $ownerRows = $byOwner->get($ownerName, collect());
                $net = (float) $ownerRows->sum('item_total');
                $gp = (float) $ownerRows->sum('item_gross_profit');
                $invoiceCount = $ownerRows->pluck('transaction_number')->unique()->count();
                $skuCount = $ownerRows->pluck('zoho_item_id')->unique()->count();
                $label = $month->format('M');

                $charts[$ownerName]['Net Sales'][] = ['label' => $label, 'value' => $net];
                $charts[$ownerName]['Avg Sales'][] = ['label' => $label, 'value' => $invoiceCount > 0 ? $net / $invoiceCount : 0];
                $charts[$ownerName]['Sales Contribution %'][] = ['label' => $label, 'value' => $customerTotalNet > 0 ? round($net / $customerTotalNet * 100, 1) : 0];
                $charts[$ownerName]['SKU Count'][] = ['label' => $label, 'value' => $skuCount];
                $charts[$ownerName]['SKU Avg Sales'][] = ['label' => $label, 'value' => $skuCount > 0 ? $net / $skuCount : 0];
                $charts[$ownerName]['Profit %'][] = ['label' => $label, 'value' => $net != 0 ? round($gp / $net * 100, 1) : 0];
                $charts[$ownerName]['Profit Contribution %'][] = ['label' => $label, 'value' => $customerTotalGp != 0 ? round($gp / $customerTotalGp * 100, 1) : 0];
            }
        }

        return $charts;
    }

    /**
     * Credit Limit tab's DSO block. DSO = (current AR balance ÷ credit
     * sales in the trailing period) × days in that period — the standard
     * textbook definition, using sales_exc_vat from ar_ledger_entries as
     * "credit sales" (this business sells on credit terms, not cash-only,
     * per the Payment Terms field on every customer). Recommended Credit
     * Limit = Average Daily Net Sales (trailing 12M) × the customer's own
     * payment terms (days) — also a standard formula, but which "target
     * DSO" the company actually wants to use is a policy call not yet
     * confirmed by Dr. Ahmed, so this is a reasonable estimate, not a
     * confirmed one (flagged to the user alongside this change).
     *
     * @return array<string, ?string>
     */
    private function buildCreditDso(?string $zohoCustomerId, Customer $customer, float $outstandingBalance, ?string $collectionClassification, ?string $salesClassification): array
    {
        if ($zohoCustomerId === null) {
            return [];
        }

        $today = Carbon::today();

        // This customer's own tenure, for capping every window below —
        // confirmed wrong live 2026-10-04 (Ahmed Mohsen, CUS-1184, a
        // customer only 172 days old): DSO(12M) divided by the full 365-day
        // window even though real sales history only spans ~172 days,
        // multiplying the balance/sales ratio by a denominator far larger
        // than the period the sales figure actually came from — DSO read
        // 137 days when the real figure (sized to the customer's own
        // history) was 64.6.
        $firstSaleDate = ArLedgerEntry::where('zoho_customer_id', $zohoCustomerId)
            ->where('category', 'SALES')->whereIn('entity_type', ['invoice', 'creditnote'])
            ->min('transaction_date');
        $customerStart = $firstSaleDate !== null ? Carbon::parse($firstSaleDate)->startOfDay() : $today->copy();

        $dso = function (int $months) use ($zohoCustomerId, $today, $outstandingBalance, $customerStart) {
            $from = $today->copy()->subMonthsNoOverflow($months)->startOfDay();
            $effectiveFrom = $from->lt($customerStart) ? $customerStart->copy() : $from;
            $days = $effectiveFrom->diffInDays($today);

            if ($days < 1) {
                return null;
            }

            $creditSales = (float) ArLedgerEntry::where('zoho_customer_id', $zohoCustomerId)
                ->whereNotNull('sales_exc_vat')
                ->whereBetween('transaction_date', [$effectiveFrom->toDateString(), $today->toDateString()])
                ->sum('sales_exc_vat');

            return $creditSales > 0 ? ($outstandingBalance / $creditSales) * $days : null;
        };

        $dso12 = $dso(12);
        $dso6 = $dso(6);
        $dso3 = $dso(3);

        // Removed the dso3-vs-dso12 "Improving/Worsening" claim entirely
        // rather than patching it — confirmed wrong live 2026-10-02 (Ahmed
        // Mohsen, CUS-263): every dso() call shares the SAME current-balance
        // numerator and only varies the trailing sales window in the
        // denominator, so dso3 dropping below dso12 just means recent sales
        // outpaced the 12-month average (September was this customer's
        // highest month all year) — it says nothing about whether customers
        // are actually paying faster, and in this exact case September's own
        // collection was only 40% of that month's sales. There's no
        // historical balance snapshot anywhere in this system (same
        // limitation buildArKpiTable()'s docblock documents for Outstanding
        // AR), so no window choice here can isolate true payment-timing
        // trend from a sales-volume swing — "Coming Soon" until this is
        // rebuilt on an actual timing signal (e.g. the Collection tab's
        // Median Settlement Delay Days trend) instead of DSO windows.
        $trend = 'Coming Soon';

        // Recommended Credit Limit — rebuilt on Ahmed Mohsen's formula
        // (2026-10-02, CUS-263), replacing the old avgDailyNetSales * DSO one
        // (confirmed algebraically circular: it always landed at ~1.018x the
        // customer's CURRENT balance, every customer, giving zero real
        // headroom — see this method's earlier state for the proof). This
        // version uses average MONTHLY net sales (not tied to the customer's
        // own outstanding balance at all), scaled by their payment terms plus
        // a grace buffer, plus a safety margin — see the two RECOMMENDED_
        // LIMIT_* constants above for why those two figures are placeholders,
        // not confirmed policy.
        // Divides by this customer's own elapsed months (capped at 12, floored
        // at ~1 day so a brand-new customer doesn't divide by zero), not a
        // flat 12 — confirmed wrong live 2026-10-04 (Ahmed Mohsen, CUS-1184):
        // dividing a ~5.7-month-old customer's real sales by 12 understated
        // their average monthly sales by more than half (25,098 vs the real
        // ~53,301), which in turn put the recommended limit BELOW their
        // current balance — reading as "lower this customer's limit" when
        // that signal was purely a division artifact, not real risk.
        $salesWindowStart = $customerStart->gt($today->copy()->subMonthsNoOverflow(12))
            ? $customerStart->copy()
            : $today->copy()->subMonthsNoOverflow(12);
        $monthsElapsed = max($salesWindowStart->diffInDays($today) / 30, 1 / 30);

        $avgMonthlyNetSales = (float) DB::table('ar_ledger_entries')->where('zoho_customer_id', $zohoCustomerId)
            ->where('category', 'SALES')->whereIn('entity_type', ['invoice', 'creditnote'])
            ->where('transaction_date', '>=', $salesWindowStart->toDateString())
            ->sum('statement_amount') / $monthsElapsed;

        $paymentTermsDays = (float) ($customer->payments_due ?? 30);

        $recommendedLimit = $avgMonthlyNetSales > 0
            ? $avgMonthlyNetSales * (($paymentTermsDays + self::RECOMMENDED_LIMIT_GRACE_DAYS) / 30)
                + $avgMonthlyNetSales * self::RECOMMENDED_LIMIT_SAFETY_MARGIN_PCT
            : null;

        $isBlocked = $this->isHighCreditRisk($collectionClassification, $salesClassification);
        $blockedReason = match (true) {
            $collectionClassification === 'Severe Delay' && $salesClassification !== null && str_contains($salesClassification, 'Lost') => 'Severe Delay, Lost',
            $collectionClassification === 'Severe Delay' => 'Severe Delay',
            default => 'Lost',
        };

        return [
            'dso_12m' => $dso12 !== null ? number_format($dso12, 0).' days' : '—',
            'dso_6m' => $dso6 !== null ? number_format($dso6, 0).' days' : '—',
            'dso_3m' => $dso3 !== null ? number_format($dso3, 0).' days' : '—',
            'dso_trend' => $trend,
            'recommended_credit_limit' => $isBlocked
                ? "0 SAR (Blocked — {$blockedReason})"
                : ($recommendedLimit !== null ? number_format($recommendedLimit, 0).' SAR' : '—'),
            'recommended_credit_limit_is_estimate' => true,
        ];
    }

    /**
     * Per Dr. Ahmed's explicit direction (voice note, 2026-09-26): Insights
     * is not a fixed 7-card list — each category independently decides
     * whether it has something worth surfacing, based on that category's
     * own classification/status already computed elsewhere on this page. A
     * customer who's fine across the board should show few or zero cards,
     * not a forced full set. The specific "is this worth flagging" cutoffs
     * below are a reasonable first pass, not confirmed formulas — easy to
     * tighten/loosen per category once real usage shows they're off.
     *
     * @return array<int, array{key: string, title: string, tint: string, body: string, meta: ?string}>
     */
    private function buildInsights(
        $classifications,
        ?CustomerSalesRecommendation $salesRecommendation,
        ?object $creditUsage,
        $documentInsightRows,
        $arAging,
        int $overdueInvoiceCount,
    ): array {
        $insights = [];

        // Sales: trust the recommendation engine's own judgment — only
        // surface it if it actually produced something to say.
        if ($salesRecommendation && ($salesRecommendation->primary_recommendation || $salesRecommendation->key_risk)) {
            $insights[] = [
                'key' => 'sales',
                'title' => 'Sales',
                'tint' => 'blue',
                'body' => $salesRecommendation->primary_recommendation ?: $salesRecommendation->key_risk,
                'meta' => $salesRecommendation->priority_level,
            ];
        }

        // Collection: flag anything worse than "On Time / Perfect".
        $collectionLabel = $classifications['collection']->classification ?? null;
        if ($collectionLabel && $collectionLabel !== 'On Time / Perfect') {
            $insights[] = [
                'key' => 'collection',
                'title' => 'Collection',
                'tint' => 'amber',
                'body' => "Payment pattern classified as \"{$collectionLabel}\".",
                'meta' => $classifications['collection']->data['Payment Timing Pattern'] ?? null,
            ];
        }

        // Profitability: flag Loss/Low levels or a declining trend.
        $profitLabel = $classifications['profitability']->classification ?? null;
        if ($profitLabel && (str_contains($profitLabel, 'Loss') || str_contains($profitLabel, 'Low') || str_contains($profitLabel, 'Declining') || str_contains($profitLabel, 'Severe'))) {
            $insights[] = [
                'key' => 'profitability',
                'title' => 'Profit',
                'tint' => 'rose',
                'body' => "Profitability classified as \"{$profitLabel}\".",
                'meta' => null,
            ];
        }

        // Documents: any open issue at all is worth a card.
        if ($documentInsightRows->isNotEmpty()) {
            $insights[] = [
                'key' => 'documents',
                'title' => 'Documentation',
                'tint' => 'amber',
                'body' => $documentInsightRows->count().' outstanding document issue(s) — see the Documentation card below for detail.',
                'meta' => null,
            ];
        }

        // Accounts Receivable: only worth a card if something's actually
        // outstanding.
        if ($overdueInvoiceCount > 0) {
            $insights[] = [
                'key' => 'ar',
                'title' => 'Accounts Receivable',
                'tint' => 'rose',
                'body' => $overdueInvoiceCount.' invoice(s) with an open balance.',
                'meta' => null,
            ];
        }

        // Due Ages: buildDueAgesTable() always returns all 8 spec buckets
        // (0 if empty), so check for actual balances rather than emptiness.
        $nonZeroAgingBuckets = $arAging->filter(fn ($b) => (float) $b->total > 0);
        if ($nonZeroAgingBuckets->isNotEmpty()) {
            $insights[] = [
                'key' => 'due_ages',
                'title' => 'Due Ages',
                'tint' => 'amber',
                'body' => 'Balances spread across '.$nonZeroAgingBuckets->count().' aging bucket(s) — see the Due Ages tab.',
                'meta' => null,
            ];
        }

        // Credit Limit: flag high utilization or a non-clean status.
        if ($creditUsage) {
            $isHighUtilization = $creditUsage->credit_utilization_pct !== null && (float) $creditUsage->credit_utilization_pct >= 80;
            $isFlaggedStatus = $creditUsage->credit_status && ! in_array($creditUsage->credit_status, ['Healthy', 'Normal', 'OK', 'Good'], true);
            if ($isHighUtilization || $isFlaggedStatus) {
                $insights[] = [
                    'key' => 'credit_limit',
                    'title' => 'Credit Limit',
                    'tint' => 'rose',
                    'body' => $creditUsage->credit_status
                        ? "Credit status: \"{$creditUsage->credit_status}\"."
                        : 'Credit utilisation at '.number_format((float) $creditUsage->credit_utilization_pct, 1).'%.',
                    'meta' => null,
                ];
            }
        }

        return $insights;
    }
}
