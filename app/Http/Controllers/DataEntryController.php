<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Data Entry directory — "Data Entry" sidebar item under Sales Data, same
 * pattern as SalesRepController but grouped by customers.data_entry
 * (Zoho's cf_data_entry custom field) instead of rep_name.
 *
 * data_entry only just started being live-synced (2026-10-14 —
 * ZohoContactMapper::mapToCustomerAttributes(); confirmed live against
 * CUS-1517, "Walid Metwaly") after this mapper never read the field at
 * all before today. As of this page's build, only 2 of 1,496 customers
 * have a value here — the rest fill in automatically as the hourly
 * contacts sync (already re-crawling from page 1 since 08:00 today)
 * catches up, same backfill pattern sales_owner went through on
 * 2026-10-09. Not a bug in this page — a real, temporary data gap.
 *
 * Unlike rep_name, data_entry has no known "not a real person" placeholder
 * value confirmed yet, so every non-empty value is treated as real (same
 * assumption SalesOwnerController makes for sales_owner).
 */
class DataEntryController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->query('search');

        // Sortable by any column shown on this page (2026-10-18, explicit
        // "make the sort icons real" request) — whitelisted against actual
        // column/alias names so the sort/direction query params can never
        // reach orderBy() unsanitized.
        $sortableColumns = ['data_entry', 'customer_count', 'outstanding_balance'];
        $sort = in_array($request->query('sort'), $sortableColumns, true) ? $request->query('sort') : 'customer_count';
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        // One row per distinct data_entry, with a real customer count and a
        // real Outstanding Balance (ar_ledger_entries.statement_amount,
        // same source customerLedgerBalance() uses on the Customer detail
        // page's AR tab). COUNT(DISTINCT ...) is load-bearing — see
        // SalesRepController::index()'s identical comment for why.
        $entries = DB::table('customers')->whereNull('zoho_missing_since')
            ->leftJoin('ar_ledger_entries', 'ar_ledger_entries.zoho_customer_id', '=', 'customers.zoho_contact_id')
            ->whereNotNull('customers.data_entry')
            ->where('customers.data_entry', '!=', '')
            ->groupBy('customers.data_entry')
            ->selectRaw('customers.data_entry, count(distinct customers.id) as customer_count, coalesce(sum(ar_ledger_entries.statement_amount), 0) as outstanding_balance')
            ->orderBy($sort, $direction)
            ->get();

        if ($search !== null && $search !== '') {
            $entries = $entries->filter(fn ($r) => str_contains(strtolower($r->data_entry), strtolower($search)))->values();
        }

        return view('data-entry.index', [
            'entries' => $entries,
            'search' => $search,
            'totalEntries' => $entries->count(),
            'totalCustomersWithEntries' => $entries->sum('customer_count'),
            'totalOutstanding' => $entries->sum('outstanding_balance'),
            'totalCustomers' => DB::table('customers')->whereNull('zoho_missing_since')->count(),
            'lastUpdated' => DB::table('customers')->max('live_contact_synced_at'),
        ]);
    }

    /**
     * One data entry person's detail page — same 11-tab shape as
     * SalesRepController::show(), reused verbatim (same aggregate KPI
     * builders, same whereIn pattern) but keyed by customers.data_entry
     * instead of rep_name. "Most common value among this person's
     * customers" is used for the Operations/Classification fields (Sales
     * Rep, Management, Warehouse City, Sales Owner, the 4 classifications)
     * — same judgment-call caveat as the Sales Rep page's own docblock.
     */
    public function show(string $dataEntryName)
    {
        $customersQuery = fn () => DB::table('customers')->whereNull('zoho_missing_since')->where('data_entry', $dataEntryName);

        $totalCustomers = $customersQuery()->count();

        if ($totalCustomers === 0) {
            abort(404);
        }

        $activeCustomers = $customersQuery()->where('status', 'Active')->count();
        $zohoCustomerIds = $customersQuery()->pluck('zoho_contact_id')->filter()->all();
        $customerNumbers = $customersQuery()->pluck('customer_number')->filter()->all();
        $customerNames = $customersQuery()->pluck('customer_name')->filter()->all();

        $mostCommon = function (string $column) use ($dataEntryName) {
            return DB::table('customers')->whereNull('zoho_missing_since')
                ->where('data_entry', $dataEntryName)
                ->whereNotNull($column)->where($column, '!=', '')
                ->selectRaw("{$column}, count(*) as cnt")
                ->groupBy($column)
                ->orderByDesc('cnt')
                ->limit(1)
                ->value($column);
        };

        // "Business Unit" lives in customers.extra->management (a free-text
        // Zoho custom field, cf_management — merged into extra by
        // ZohoContactSyncJob::mergeLiveTargetFields(), not a dedicated
        // column the way rep_name/sales_owner/data_entry are), hence the
        // JSON extraction instead of $mostCommon().
        $businessUnit = DB::table('customers')->whereNull('zoho_missing_since')
            ->where('data_entry', $dataEntryName)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(extra, '$.management')) IS NOT NULL")
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(extra, '$.management')) != ''")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(extra, '$.management')) as mgmt, count(*) as cnt")
            ->groupBy('mgmt')
            ->orderByDesc('cnt')
            ->limit(1)
            ->value('mgmt');

        $mostCommonClassification = function (string $type) use ($customerNumbers) {
            if (empty($customerNumbers)) {
                return null;
            }

            return DB::table('customer_classifications')
                ->where('type', $type)
                ->whereIn('customer_number', $customerNumbers)
                ->selectRaw('classification, count(*) as cnt')
                ->groupBy('classification')
                ->orderByDesc('cnt')
                ->limit(1)
                ->value('classification');
        };

        $paymentTermsBuckets = $customersQuery()
            ->whereIn('payment_terms_label', ['0', '30', '45', '60', '90'])
            ->selectRaw('payment_terms_label, count(*) as cnt')
            ->groupBy('payment_terms_label')
            ->pluck('cnt', 'payment_terms_label');

        // Net Sales (Lifetime) across every one of this rep's customers,
        // for rank/contribution — same ar_ledger_entries source and SALES
        // category CustomerController::buildSalesKpiTable() uses per
        // customer, just summed over the whole rep instead of filtered to
        // one zoho_customer_id.
        $repNetSalesLifetime = empty($zohoCustomerIds) ? 0.0 : (float) DB::table('ar_ledger_entries')
            ->whereIn('zoho_customer_id', $zohoCustomerIds)
            ->where('category', 'SALES')->whereIn('entity_type', ['invoice', 'creditnote'])
            ->sum('statement_amount');

        // Every OTHER rep's own Net Sales (Lifetime), for this rep's rank —
        // grouped by rep_name rather than per-customer so "Company" here
        // means "among reps", matching this page's own subject.
        $allRepTotals = DB::table('customers')->whereNull('zoho_missing_since')
            ->join('ar_ledger_entries', 'ar_ledger_entries.zoho_customer_id', '=', 'customers.zoho_contact_id')
            ->whereNotNull('customers.data_entry')->where('customers.data_entry', '!=', '')
            ->where('ar_ledger_entries.category', 'SALES')
            ->whereIn('ar_ledger_entries.entity_type', ['invoice', 'creditnote'])
            ->selectRaw('customers.data_entry, sum(ar_ledger_entries.statement_amount) as net_sales')
            ->groupBy('customers.data_entry')
            ->havingRaw('sum(ar_ledger_entries.statement_amount) > 0')
            ->orderByDesc('net_sales')
            ->pluck('data_entry')
            ->values();
        $revenueRank = $allRepTotals->search($dataEntryName);
        $revenueRank = $revenueRank === false ? null : $revenueRank + 1;

        $lastSalesOrderDate = empty($zohoCustomerIds) ? null : DB::table('ar_ledger_entries')
            ->whereIn('zoho_customer_id', $zohoCustomerIds)
            ->where('category', 'SALES')
            ->max('transaction_date');

        $lastPaymentDate = empty($zohoCustomerIds) ? null : DB::table('ar_ledger_entries')
            ->whereIn('zoho_customer_id', $zohoCustomerIds)
            ->where('entity_type', 'customer_payment')
            ->max('transaction_date');

        $insightsCount = empty($customerNumbers) ? 0 : DB::table('customer_classifications')
            ->whereIn('customer_number', $customerNumbers)
            ->count();

        $outstandingBalance = empty($zohoCustomerIds) ? 0.0 : (float) DB::table('ar_ledger_entries')
            ->whereIn('zoho_customer_id', $zohoCustomerIds)
            ->sum('statement_amount');

        $ytdMonthExtremes = $this->entryYtdRevenueMonthExtremes($zohoCustomerIds);

        return view('data-entry.show', [
            'dataEntryName' => $dataEntryName,
            'totalCustomers' => $totalCustomers,
            'activeCustomers' => $activeCustomers,
            'inactiveCustomers' => $totalCustomers - $activeCustomers,
            'businessUnit' => $businessUnit,
            'totalCreditLimit' => $customersQuery()->sum('credit_limit'),
            'attestedYes' => $customersQuery()->where('attested_account', 'YES')->count(),
            'attestedNo' => $customersQuery()->where('attested_account', 'NO')->count(),
            'paymentTermsBuckets' => $paymentTermsBuckets,
            'repName' => $mostCommon('rep_name'),
            'salesOwner' => $mostCommon('sales_owner'),
            'warehouseCity' => $mostCommon('warehouse_city'),
            'salesClassification' => $mostCommonClassification('sales'),
            'collectionClassification' => $mostCommonClassification('collection'),
            'profitClassification' => $mostCommonClassification('profitability'),
            'documentsClassification' => $mostCommonClassification('documents'),
            'outstandingBalance' => $outstandingBalance,
            'repNetSalesLifetime' => $repNetSalesLifetime,
            'revenueRank' => $revenueRank,
            'totalRepsRanked' => $allRepTotals->count(),
            'lastSalesOrderDate' => $lastSalesOrderDate,
            'lastPaymentDate' => $lastPaymentDate,
            'insightsCount' => $insightsCount,
            'salesKpiTable' => $this->buildEntrySalesKpiTable($zohoCustomerIds, $dataEntryName),
            'salesCharts' => $this->buildEntrySalesCharts($zohoCustomerIds),
            'creditNoteKpiTable' => $this->buildEntryCreditNoteKpiTable($zohoCustomerIds),
            'creditNoteCharts' => $this->buildEntryCreditNoteCharts($zohoCustomerIds),
            'salesOrdersKpiTable' => $this->buildEntrySalesOrdersKpiTable($customerNames),
            'salesOrdersCharts' => $this->buildEntrySalesOrdersCharts($customerNames),
            'profitabilityKpiTable' => $this->buildEntryProfitabilityKpiTable($zohoCustomerIds, $dataEntryName),
            'profitabilityCharts' => $this->buildEntryProfitabilityCharts($zohoCustomerIds),
            'collectionKpiTable' => $this->buildEntryCollectionKpiTable($zohoCustomerIds, $dataEntryName),
            'collectionCharts' => $this->buildEntryCollectionCharts($zohoCustomerIds),
            'arKpiTable' => $this->buildEntryArKpiTable($zohoCustomerIds, (float) $outstandingBalance),
            'arSnapshot' => $this->buildEntryArSnapshot($zohoCustomerIds, (float) $outstandingBalance),
            'arAging' => $this->buildEntryDueAgesTable($zohoCustomerIds),
            'dueAgesChart' => $this->buildEntryDueAgesChart($zohoCustomerIds),
            'creditUsage' => $this->buildEntryCreditUsage((float) $customersQuery()->sum('credit_limit'), (float) $outstandingBalance),
            'creditDso' => $this->buildEntryCreditDso($zohoCustomerIds, $dataEntryName, (float) $outstandingBalance, $mostCommonClassification('collection'), $mostCommonClassification('sales')),
            'itemPerformanceOwners' => $this->buildEntryItemPerformanceData($zohoCustomerIds),
            'ytdHighestMonth' => $ytdMonthExtremes[0],
            'ytdLowestMonth' => $ytdMonthExtremes[1],
            'overviewTargets' => $this->buildEntryOverviewTargets($zohoCustomerIds, $dataEntryName),
        ]);
    }

    /**
     * Rep-level version of CustomerController::buildSalesKpiTable() — same
     * ar_ledger_entries source, periods and formulas, but every figure is
     * summed/ranked across this rep's whole customer set (whereIn) instead
     * of one zoho_customer_id. Deliberately a separate method rather than
     * widening the Customer page's own method to accept an array: this page
     * is net-new and the Customer page's KPI logic is already
     * production-proven, so it stays untouched to avoid any regression risk
     * there (per the "من الاكبر للأصغر بس مهم منبوظش حاجة" instruction).
     * "Revenue Rank - Company" here ranks this REP among all reps (by
     * Net Sales), not this rep's customers among all customers — matching
     * what this page is about.
     */
    private function buildEntrySalesKpiTable(array $zohoCustomerIds, string $dataEntryName): array
    {
        if (empty($zohoCustomerIds)) {
            return [];
        }

        $firstSaleDate = DB::table('ar_ledger_entries')
            ->whereIn('zoho_customer_id', $zohoCustomerIds)
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

        $firstActivity = DB::table('ar_ledger_entries')->whereIn('zoho_customer_id', $zohoCustomerIds)->min('transaction_date');
        $firstActivity = $firstActivity !== null ? Carbon::parse($firstActivity)->startOfDay() : null;

        foreach ($ranges as $label => [$from, $to, $bucket]) {
            if ($firstActivity !== null && $to->lt($firstActivity)) {
                $table[$label] = [
                    'Net Sales' => '—', 'Gross Sales' => '—', 'Sales Average' => '—', 'Sales Median' => '—',
                    'Revenue Volatility %' => '—', 'Revenue Contribution %' => '—', 'Revenue Rank - Company' => '—',
                    'Invoice Count' => '—', 'Average Invoice Value' => '—', 'Largest Invoice' => '—',
                ];

                continue;
            }

            $netSales = (float) DB::table('ar_ledger_entries')
                ->whereIn('zoho_customer_id', $zohoCustomerIds)->where('category', 'SALES')
                ->whereIn('entity_type', ['invoice', 'creditnote'])
                ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
                ->sum('statement_amount');

            $grossSales = (float) DB::table('ar_ledger_entries')
                ->whereIn('zoho_customer_id', $zohoCustomerIds)->where('category', 'SALES')
                ->where('entity_type', 'invoice')
                ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
                ->sum('statement_amount');

            $invoiceCount = DB::table('ar_ledger_entries')
                ->whereIn('zoho_customer_id', $zohoCustomerIds)->where('category', 'SALES')
                ->where('entity_type', 'invoice')
                ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
                ->count();

            $largestInvoice = DB::table('ar_ledger_entries')
                ->whereIn('zoho_customer_id', $zohoCustomerIds)->where('category', 'SALES')
                ->where('entity_type', 'invoice')
                ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
                ->max('statement_amount');

            $companyFrom = $label === 'Lifetime' ? null : $from;
            $companyNetSales = (float) DB::table('ar_ledger_entries')
                ->where('category', 'SALES')->whereIn('entity_type', ['invoice', 'creditnote'])
                ->when($companyFrom !== null, fn ($q) => $q->where('transaction_date', '>=', $companyFrom->toDateString()))
                ->where('transaction_date', '<=', $to->toDateString())
                ->sum('statement_amount');

            $rankInfo = $this->entryRevenueRankAndCount($dataEntryName, $companyFrom, $to);

            $seriesFrom = $from->lt($lifetimeStart) ? $lifetimeStart->copy() : $from;

            if ($bucket !== null && $seriesFrom->lte($to)) {
                $series = $this->entrySalesPeriodSeries($zohoCustomerIds, $seriesFrom, $to, $bucket);
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

    /** Zero-filled per-month/per-day Net Sales series across this rep's whole customer set. */
    private function entrySalesPeriodSeries(array $zohoCustomerIds, Carbon $from, Carbon $to, string $bucket): \Illuminate\Support\Collection
    {
        $query = DB::table('ar_ledger_entries')
            ->whereIn('zoho_customer_id', $zohoCustomerIds)->where('category', 'SALES')
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
     * Rep-level rank among every other rep (by Net Sales, invoices + credit
     * notes, among reps with positive Net Sales in the period) — the rep
     * page's equivalent of CustomerController::salesRevenueRankAndCount().
     *
     * @return array{rank: ?int, total: int}
     */
    private function entryRevenueRankAndCount(?string $dataEntryName, ?Carbon $from, Carbon $to): array
    {
        $totals = DB::table('customers')->whereNull('zoho_missing_since')
            ->join('ar_ledger_entries', 'ar_ledger_entries.zoho_customer_id', '=', 'customers.zoho_contact_id')
            ->whereNotNull('customers.data_entry')->where('customers.data_entry', '!=', '')
            ->where('ar_ledger_entries.category', 'SALES')->whereIn('ar_ledger_entries.entity_type', ['invoice', 'creditnote'])
            ->when($from !== null, fn ($q) => $q->where('ar_ledger_entries.transaction_date', '>=', $from->toDateString()))
            ->where('ar_ledger_entries.transaction_date', '<=', $to->toDateString())
            ->select('customers.data_entry')->selectRaw('SUM(ar_ledger_entries.statement_amount) as total')
            ->groupBy('customers.data_entry')
            ->havingRaw('SUM(ar_ledger_entries.statement_amount) > 0')
            ->orderByDesc('total')
            ->pluck('data_entry')->values();

        $position = $dataEntryName !== null ? $totals->search($dataEntryName) : false;

        return ['rank' => $position === false ? null : $position + 1, 'total' => $totals->count()];
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

        $n = $values->count();
        $variance = $values->map(fn ($v) => ($v - $mean) ** 2)->sum() / ($n - 1);

        return number_format(sqrt($variance) / $mean * 100, 1).'%';
    }

    /**
     * Rep-level version of CustomerController::buildReturnKpiTable() —
     * same ar_ledger_entries source (category=SALES, entity_type=creditnote)
     * and formulas, but summed across this rep's whole customer set
     * (whereIn) instead of one zoho_customer_id. Separate method, not a
     * widened original — same reasoning as buildEntrySalesKpiTable()'s
     * docblock.
     */
    private function buildEntryCreditNoteKpiTable(array $zohoCustomerIds): array
    {
        if (empty($zohoCustomerIds)) {
            return [];
        }

        $firstSaleDate = DB::table('ar_ledger_entries')
            ->whereIn('zoho_customer_id', $zohoCustomerIds)->where('category', 'SALES')
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
                ->whereIn('zoho_customer_id', $zohoCustomerIds)->where('category', 'SALES')
                ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()]);

            $returnValue = abs((float) $baseQuery()->where('entity_type', 'creditnote')->sum('statement_amount'));
            $creditNotesCount = $baseQuery()->where('entity_type', 'creditnote')->count();
            $grossSales = (float) $baseQuery()->where('entity_type', 'invoice')->sum('statement_amount');

            $seriesFrom = $from->lt($lifetimeStart) ? $lifetimeStart->copy() : $from;

            if ($bucket !== null && $seriesFrom->lte($to)) {
                $series = $this->entryLedgerPeriodSeries($zohoCustomerIds, 'SALES', 'statement_amount', 'creditnote', $seriesFrom, $to, $bucket)
                    ->map(fn ($v) => abs($v));
                $unit = $bucket === 'month' ? '/mo' : '/day';
                $avg = $money((float) $series->avg()).' '.$unit;
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

    /** Rep-level version of CustomerController::ledgerPeriodSeries() — whereIn across this rep's customer set. */
    private function entryLedgerPeriodSeries(array $zohoCustomerIds, string $category, string $amountColumn, ?string $entityType, Carbon $from, Carbon $to, string $bucket): \Illuminate\Support\Collection
    {
        $query = DB::table('ar_ledger_entries')
            ->whereIn('zoho_customer_id', $zohoCustomerIds)->where('category', $category)
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

    /** Rep-level version of CustomerController::buildReturnCharts() — rolling trailing-12-months. */
    private function buildEntryCreditNoteCharts(array $zohoCustomerIds): array
    {
        if (empty($zohoCustomerIds)) {
            return [];
        }

        $start = Carbon::today()->subMonthsNoOverflow(11)->startOfMonth();

        $returnRows = DB::table('ar_ledger_entries')
            ->whereIn('zoho_customer_id', $zohoCustomerIds)->where('category', 'SALES')
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

    /** Rep-level version of CustomerController::periodRanges(). */
    private function entryPeriodRanges(): array
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
     * Rep-level version of CustomerController::matchCustomerName() —
     * widened to a whole rep's customer-name list instead of one name.
     * sale_items/return_items have no customer id column, only
     * customer_name (see CustomerController::matchCustomerName()'s
     * docblock for the trailing-suffix reasoning), so an aggregate match
     * needs the same exact-or-prefix OR per name. Measured live against
     * sale_items for this rep's biggest customer set (118 names): ~0.15s
     * per query — acceptable for a page load.
     */
    private function entryMatchCustomerNames(\Illuminate\Database\Query\Builder $query, array $customerNames): \Illuminate\Database\Query\Builder
    {
        return $query->where(function ($q) use ($customerNames) {
            foreach ($customerNames as $name) {
                $q->orWhere('customer_name', $name)->orWhere('customer_name', 'like', $name.' %');
            }
        });
    }

    /** Rep-level version of CustomerController::skuPriceMap(). */
    private function entrySkuPriceMap(): \Illuminate\Support\Collection
    {
        return DB::table('inventory_items')
            ->select('sku')->selectRaw('AVG(price) as price')
            ->groupBy('sku')
            ->pluck('price', 'sku')
            ->map(fn ($v) => (float) $v);
    }

    /** Rep-level version of CustomerController::estimateValueBySku(). */
    private function entryEstimateValueBySku(\Illuminate\Support\Collection $qtyBySku, \Illuminate\Support\Collection $skuPrices): float
    {
        $total = 0.0;
        foreach ($qtyBySku as $sku => $qty) {
            $total += (float) $qty * (float) ($skuPrices[$sku] ?? 0);
        }

        return $total;
    }

    /**
     * Rep-level version of CustomerController::buildQuantityKpiTable() +
     * buildSalesOrdersStatusTable(), merged the same way
     * CustomerController::show() merges them (Order Quantity/Count/SKU
     * Count from the first, Value/Pending/Lost/Fulfillment from the
     * second) — summed across this rep's whole customer-name set instead
     * of one customer.
     */
    private function buildEntrySalesOrdersKpiTable(array $customerNames): array
    {
        if (empty($customerNames)) {
            return [];
        }

        $skuPrices = $this->entrySkuPriceMap();
        $lostStatuses = ['cancelled', 'void'];
        $fulfilledStatuses = ['completed', 'confirmed', 'fulfilled'];

        $result = [];
        foreach ($this->entryPeriodRanges() as $label => [$from, $to]) {
            $baseQuery = fn () => $this->entryMatchCustomerNames(DB::table('sale_items')->whereNull('zoho_missing_since'), $customerNames)
                ->where('status', '!=', 'draft')
                ->when($from !== null, fn ($q) => $q->whereBetween('order_date', [$from->toDateString(), $to->toDateString()]));

            $result[$label] = [
                'Order Quantity' => number_format((int) $baseQuery()->sum('quantity')),
                'Order Count' => number_format($baseQuery()->distinct('document_number')->count('document_number')),
                'SKU Count' => number_format($baseQuery()->distinct('sku')->count('sku')),
            ];

            $bySkuStatus = $baseQuery()
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

            $totalValue = $this->entryEstimateValueBySku($qtyBySku($bySkuStatus), $skuPrices);
            $pendingValue = $this->entryEstimateValueBySku($qtyBySku($pendingRows), $skuPrices);
            $lostValue = $this->entryEstimateValueBySku($qtyBySku($lostRows), $skuPrices);
            $fulfilledValue = $this->entryEstimateValueBySku($qtyBySku($fulfilledRows), $skuPrices);

            $result[$label] = array_merge($result[$label], [
                'Sales Order Value' => 'SAR '.number_format($totalValue, 0).' (est.)',
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
            ]);
        }

        return $result;
    }

    /** Rep-level version of CustomerController::buildSalesOrdersCharts() — rolling trailing-12-months. */
    private function buildEntrySalesOrdersCharts(array $customerNames): array
    {
        if (empty($customerNames)) {
            return [];
        }

        $skuPrices = $this->entrySkuPriceMap();
        $lostStatuses = ['cancelled', 'void'];
        $fulfilledStatuses = ['completed', 'confirmed', 'fulfilled'];
        $start = Carbon::today()->subMonthsNoOverflow(11)->startOfMonth();

        $baseQuery = fn () => $this->entryMatchCustomerNames(DB::table('sale_items')->whereNull('zoho_missing_since'), $customerNames)
            ->where('status', '!=', 'draft')
            ->where('order_date', '>=', $start->toDateString());

        $rows = $baseQuery()
            ->selectRaw('YEAR(order_date) as y, MONTH(order_date) as m, status')
            ->selectRaw('SUM(quantity) as qty')
            ->groupBy('y', 'm', 'status')->get()
            ->groupBy(fn ($r) => sprintf('%04d-%02d', $r->y, $r->m));

        $skuRows = $baseQuery()
            ->selectRaw('YEAR(order_date) as y, MONTH(order_date) as m, status, sku')
            ->distinct()
            ->get()
            ->groupBy(fn ($r) => sprintf('%04d-%02d', $r->y, $r->m));

        $qtyBySkuRows = $baseQuery()
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

            $sumBySku = fn ($rows) => $rows->groupBy('sku')->map(fn ($r) => $r->sum('qty'));
            $totalValue = $this->entryEstimateValueBySku($sumBySku($monthQtyBySku), $skuPrices);
            $fulfilledValue = $this->entryEstimateValueBySku($sumBySku($monthQtyBySku->whereIn('status', $fulfilledStatuses)), $skuPrices);
            $lostValue = $this->entryEstimateValueBySku($sumBySku($monthQtyBySku->whereIn('status', $lostStatuses)), $skuPrices);

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

    /** Rep-level version of CustomerController::customerFirstActivityDate() — whereIn across this rep's customer set. */
    private function entryFirstActivityDate(array $zohoCustomerIds): ?Carbon
    {
        if (empty($zohoCustomerIds)) {
            return null;
        }

        $date = DB::table('ar_ledger_entries')->whereIn('zoho_customer_id', $zohoCustomerIds)->min('transaction_date');

        return $date !== null ? Carbon::parse($date)->startOfDay() : null;
    }

    /**
     * Rep-level version of CustomerController::buildProfitabilityKpiTable()
     * — same sales_exc_vat/cogs recompute (not the hard-zeroed stored
     * gross_profit column, see that method's docblock for why), same
     * periods/formulas, summed across this rep's whole customer set
     * (whereIn) instead of one zoho_customer_id. "GP Rank" here ranks this
     * REP among all reps, same convention as Revenue Rank on the Sales tab.
     */
    private function buildEntryProfitabilityKpiTable(array $zohoCustomerIds, string $dataEntryName): array
    {
        if (empty($zohoCustomerIds)) {
            return [];
        }

        $money = fn (float $v) => 'SAR '.number_format($v, 0);
        $table = [];
        $firstActivity = $this->entryFirstActivityDate($zohoCustomerIds);

        foreach ($this->entryPeriodRanges() as $label => [$from, $to]) {
            if ($to !== null && $firstActivity !== null && $to->lt($firstActivity)) {
                $table[$label] = [
                    'Gross Profit' => '—', 'Gross Margin %' => '—', 'GP Contribution %' => '—',
                    'GP Rank' => '—', 'Average GP% per Invoice' => '—',
                ];

                continue;
            }

            $baseQuery = fn () => DB::table('ar_ledger_entries')
                ->whereIn('zoho_customer_id', $zohoCustomerIds)
                ->whereIn('entity_type', ['invoice', 'creditnote'])->whereNotNull('sales_exc_vat')
                ->when($from !== null, fn ($q) => $q->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()]));

            $companyQuery = fn () => DB::table('ar_ledger_entries')
                ->whereIn('entity_type', ['invoice', 'creditnote'])->whereNotNull('sales_exc_vat')
                ->when($from !== null, fn ($q) => $q->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()]));

            $invoiceOnlyQuery = fn () => DB::table('ar_ledger_entries')
                ->whereIn('zoho_customer_id', $zohoCustomerIds)
                ->where('entity_type', 'invoice')->whereNotNull('sales_exc_vat')
                ->when($from !== null, fn ($q) => $q->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()]));

            $row = $baseQuery()->selectRaw('SUM(sales_exc_vat) as sales, SUM(cogs) as cogs')->first();
            $sales = (float) ($row->sales ?? 0);
            $cogs = (float) ($row->cogs ?? 0);
            $gp = $sales - $cogs;
            $companyRow = $companyQuery()->selectRaw('SUM(sales_exc_vat) as sales, SUM(cogs) as cogs')->first();
            $companyGp = (float) ($companyRow->sales ?? 0) - (float) ($companyRow->cogs ?? 0);

            $invoiceMargins = $invoiceOnlyQuery()
                ->select('transaction_number')
                ->selectRaw('SUM(sales_exc_vat) as sales, SUM(gross_profit) as gp')
                ->groupBy('transaction_number')
                ->get()
                ->filter(fn ($r) => (float) $r->sales != 0)
                ->map(fn ($r) => (float) $r->gp / (float) $r->sales * 100)
                ->filter(fn ($m) => $m >= -100);

            $rankInfo = $this->entryProfitabilityRankAndCount($dataEntryName, $from, $to);

            $table[$label] = [
                'Gross Profit' => $money($gp),
                'Gross Margin %' => $sales != 0 ? number_format($gp / $sales * 100, 1).'%' : '—',
                'GP Contribution %' => ($sales != 0 && $companyGp != 0) ? number_format($gp / $companyGp * 100, 2).'%' : '—',
                'GP Rank' => $rankInfo['rank'] !== null ? '#'.$rankInfo['rank'].' / '.number_format($rankInfo['total']) : '—',
                'Average GP% per Invoice' => $invoiceMargins->isNotEmpty() ? number_format($invoiceMargins->avg(), 1).'%' : '—',
            ];
        }

        return $table;
    }

    /**
     * Rep-level rank among every other rep, by Gross Profit (sales_exc_vat
     * − cogs, net of returns, among reps with positive GP in the period) —
     * the rep page's equivalent of CustomerController::profitabilityRankAndCount().
     *
     * @return array{rank: ?int, total: int}
     */
    private function entryProfitabilityRankAndCount(?string $dataEntryName, ?Carbon $from, ?Carbon $to): array
    {
        $totals = DB::table('customers')->whereNull('zoho_missing_since')
            ->join('ar_ledger_entries', 'ar_ledger_entries.zoho_customer_id', '=', 'customers.zoho_contact_id')
            ->whereNotNull('customers.data_entry')->where('customers.data_entry', '!=', '')
            ->whereIn('ar_ledger_entries.entity_type', ['invoice', 'creditnote'])->whereNotNull('ar_ledger_entries.sales_exc_vat')
            ->when($from !== null, fn ($q) => $q->where('ar_ledger_entries.transaction_date', '>=', $from->toDateString()))
            ->when($to !== null, fn ($q) => $q->where('ar_ledger_entries.transaction_date', '<=', $to->toDateString()))
            ->select('customers.data_entry')->selectRaw('SUM(ar_ledger_entries.sales_exc_vat) - SUM(ar_ledger_entries.cogs) as total')
            ->groupBy('customers.data_entry')
            ->havingRaw('SUM(ar_ledger_entries.sales_exc_vat) - SUM(ar_ledger_entries.cogs) > 0')
            ->orderByDesc('total')
            ->pluck('data_entry')->values();

        $position = $dataEntryName !== null ? $totals->search($dataEntryName) : false;

        return ['rank' => $position === false ? null : $position + 1, 'total' => $totals->count()];
    }

    /** Rep-level version of CustomerController::buildProfitabilityCharts() — rolling trailing-12-months. */
    private function buildEntryProfitabilityCharts(array $zohoCustomerIds): array
    {
        if (empty($zohoCustomerIds)) {
            return [];
        }

        $start = Carbon::today()->subMonthsNoOverflow(11)->startOfMonth();

        $rows = DB::table('ar_ledger_entries')
            ->whereIn('zoho_customer_id', $zohoCustomerIds)
            ->whereIn('entity_type', ['invoice', 'creditnote'])->whereNotNull('sales_exc_vat')
            ->where('transaction_date', '>=', $start->toDateString())
            ->selectRaw('YEAR(transaction_date) as y, MONTH(transaction_date) as m')
            ->selectRaw('SUM(sales_exc_vat) as sales, SUM(cogs) as cogs')
            ->groupBy('y', 'm')->get()
            ->keyBy(fn ($r) => sprintf('%04d-%02d', $r->y, $r->m));

        $invoiceRows = DB::table('ar_ledger_entries')
            ->whereIn('zoho_customer_id', $zohoCustomerIds)
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
     * Rep-level rank among every other rep for an arbitrary ar_ledger_entries
     * category/column — the rep page's equivalent of
     * CustomerController::ledgerRankAndCount(), grouped by rep_name instead
     * of per-customer.
     *
     * @return array{rank: ?int, total: int}
     */
    private function entryLedgerRankAndCount(string $category, string $amountColumn, ?string $entityType, ?string $dataEntryName, ?Carbon $from, ?Carbon $to): array
    {
        $totals = DB::table('customers')->whereNull('zoho_missing_since')
            ->join('ar_ledger_entries', 'ar_ledger_entries.zoho_customer_id', '=', 'customers.zoho_contact_id')
            ->whereNotNull('customers.data_entry')->where('customers.data_entry', '!=', '')
            ->where('ar_ledger_entries.category', $category)
            ->when($entityType !== null, fn ($q) => $q->where('ar_ledger_entries.entity_type', $entityType))
            ->when($from !== null, fn ($q) => $q->where('ar_ledger_entries.transaction_date', '>=', $from->toDateString()))
            ->when($to !== null, fn ($q) => $q->where('ar_ledger_entries.transaction_date', '<=', $to->toDateString()))
            ->select('customers.data_entry')->selectRaw("SUM(ar_ledger_entries.{$amountColumn}) as total")
            ->groupBy('customers.data_entry')
            ->havingRaw("SUM(ar_ledger_entries.{$amountColumn}) > 0")
            ->orderByDesc('total')
            ->pluck('data_entry')->values();

        $position = $dataEntryName !== null ? $totals->search($dataEntryName) : false;

        return ['rank' => $position === false ? null : $position + 1, 'total' => $totals->count()];
    }

    /** Rep-level version of CustomerController::paymentIntervalDaysForPeriod() — whereIn across this rep's customer set. */
    private function entryPaymentIntervalDaysForPeriod(array $zohoCustomerIds, Carbon $from, Carbon $to): ?float
    {
        $dates = DB::table('ar_ledger_entries')
            ->whereIn('zoho_customer_id', $zohoCustomerIds)->where('category', 'COLLECTION')
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
     * Rep-level version of CustomerController::buildCollectionKpiTable() —
     * same ar_ledger_entries (category=COLLECTION) source and formulas,
     * summed across this rep's whole customer set (whereIn) instead of one
     * zoho_customer_id. "Collection Rank - Company" here ranks this REP
     * among all reps, same convention as Revenue Rank / GP Rank.
     */
    private function buildEntryCollectionKpiTable(array $zohoCustomerIds, string $dataEntryName): array
    {
        if (empty($zohoCustomerIds)) {
            return [];
        }

        $firstCollectionDate = DB::table('ar_ledger_entries')
            ->whereIn('zoho_customer_id', $zohoCustomerIds)->where('category', 'COLLECTION')
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
        $firstActivity = $this->entryFirstActivityDate($zohoCustomerIds);

        foreach ($ranges as $label => [$from, $to, $bucket]) {
            if ($firstActivity !== null && $to->lt($firstActivity)) {
                $table[$label] = [
                    'Collection' => '—', 'Collection Ratio vs Sales' => '—', 'Collection Average' => '—',
                    'Collection Median' => '—', 'Collection Volatility %' => '—', 'Collection Contribution %' => '—',
                    'Collection Rank - Company' => '—', 'Payment Count' => '—', 'Average Payment Value' => '—',
                    'Largest Payment Value' => '—', 'Average Payment Interval' => '—',
                ];

                continue;
            }

            $companyFrom = $label === 'Lifetime' ? null : $from;

            $rows = DB::table('ar_ledger_entries')
                ->whereIn('zoho_customer_id', $zohoCustomerIds)->where('category', 'COLLECTION')
                ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
                ->pluck('kpi_amount')->map(fn ($v) => (float) $v);

            $collected = $rows->sum();

            $paymentRows = DB::table('ar_ledger_entries')
                ->whereIn('zoho_customer_id', $zohoCustomerIds)->where('category', 'COLLECTION')
                ->whereIn('entity_type', ['customer_payment', 'invoice_payment'])
                ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
                ->pluck('kpi_amount')->map(fn ($v) => (float) $v);

            $count = $paymentRows->count();
            $largest = $paymentRows->isEmpty() ? null : $paymentRows->max();

            $companyCollected = (float) DB::table('ar_ledger_entries')->where('category', 'COLLECTION')
                ->when($companyFrom !== null, fn ($q) => $q->where('transaction_date', '>=', $companyFrom->toDateString()))
                ->where('transaction_date', '<=', $to->toDateString())
                ->sum('kpi_amount');

            $netSales = (float) DB::table('ar_ledger_entries')
                ->whereIn('zoho_customer_id', $zohoCustomerIds)->where('category', 'SALES')
                ->whereIn('entity_type', ['invoice', 'creditnote'])
                ->when($companyFrom !== null, fn ($q) => $q->where('transaction_date', '>=', $companyFrom->toDateString()))
                ->where('transaction_date', '<=', $to->toDateString())
                ->sum('statement_amount');

            $rankInfo = $this->entryLedgerRankAndCount('COLLECTION', 'kpi_amount', null, $dataEntryName, $companyFrom, $to);
            $avgIntervalDays = $this->entryPaymentIntervalDaysForPeriod($zohoCustomerIds, $from, $to);

            $seriesFrom = $from->lt($lifetimeStart) ? $lifetimeStart->copy() : $from;

            if ($bucket !== null && $seriesFrom->lte($to)) {
                $series = $this->entryLedgerPeriodSeries($zohoCustomerIds, 'COLLECTION', 'kpi_amount', null, $seriesFrom, $to, $bucket);
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

    /** Rep-level version of CustomerController::buildCollectionCharts() — rolling trailing-12-months. */
    private function buildEntryCollectionCharts(array $zohoCustomerIds): array
    {
        if (empty($zohoCustomerIds)) {
            return [];
        }

        $start = Carbon::today()->subMonthsNoOverflow(11)->startOfMonth();
        $rows = DB::table('ar_ledger_entries')
            ->whereIn('zoho_customer_id', $zohoCustomerIds)->where('category', 'COLLECTION')
            ->where('transaction_date', '>=', $start->toDateString())
            ->selectRaw('YEAR(transaction_date) as y, MONTH(transaction_date) as m')
            ->selectRaw('SUM(kpi_amount) as total')
            ->groupBy('y', 'm')->get()
            ->keyBy(fn ($r) => sprintf('%04d-%02d', $r->y, $r->m));

        $paymentRows = DB::table('ar_ledger_entries')
            ->whereIn('zoho_customer_id', $zohoCustomerIds)->where('category', 'COLLECTION')
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
     * Rep-level version of CustomerController::buildArKpiTable() — same
     * invoice_documents source, summed across this rep's whole customer
     * set (whereIn) instead of one zoho_customer_id. $outstandingBalance
     * is the already-computed rep-aggregate of customerLedgerBalance()
     * (show()'s own $outstandingBalance, same as CustomerController passes
     * its own customerLedgerBalance() result in).
     */
    private function buildEntryArKpiTable(array $zohoCustomerIds, float $outstandingBalance): array
    {
        if (empty($zohoCustomerIds)) {
            return [];
        }

        $today = Carbon::today();
        $table = [];

        foreach ($this->entryPeriodRanges() as $label => [$from, $to]) {
            $docs = DB::table('invoice_documents')->whereIn('zoho_customer_id', $zohoCustomerIds)->where('status', '!=', 'draft');
            $companyDocs = DB::table('invoice_documents')->where('status', '!=', 'draft');
            if ($from !== null) {
                $docs->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()]);
                $companyDocs->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()]);
            }

            $outstanding = $from === null ? $outstandingBalance : (float) (clone $docs)->sum('balance');
            $outstandingCount = (clone $docs)->where('balance', '>', 0)->count();
            $companyOutstanding = (float) (clone $companyDocs)->sum('balance');

            $overdueAmount = (float) (clone $docs)->where('balance', '>', 0)->where('due_date', '<', $today->toDateString())->sum('balance');
            $overdueCount = (clone $docs)->where('balance', '>', 0)->where('due_date', '<', $today->toDateString())->count();
            $companyOverdue = (float) (clone $companyDocs)->where('balance', '>', 0)->where('due_date', '<', $today->toDateString())->sum('balance');

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

    /** Rep-level version of CustomerController::buildArSnapshot() — the 4 current-state donut ratios. */
    private function buildEntryArSnapshot(array $zohoCustomerIds, float $outstandingBalance): array
    {
        if (empty($zohoCustomerIds)) {
            return [];
        }

        $today = Carbon::today();
        $docs = DB::table('invoice_documents')->whereIn('zoho_customer_id', $zohoCustomerIds)->where('status', '!=', 'draft');

        $overdueAmount = (float) (clone $docs)->where('balance', '>', 0)->where('due_date', '<', $today->toDateString())->sum('balance');
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

    /** Rep-level version of CustomerController::ledgerAgingLabel(). */
    private function entryLedgerAgingLabel(string $zohoBucket): ?string
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

    /** Rep-level version of CustomerController::ageDaysToBucket(). */
    private function entryAgeDaysToBucket(int $days): string
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
     * Rep-level version of CustomerController::buildDueAgesTable() —
     * same ar_ledger_entries.balance_aging source, summed across this
     * rep's whole customer set (whereIn) instead of one zoho_customer_id.
     *
     * @return \Illuminate\Support\Collection<int, object{balance_aging: string, total: float, count: int}>
     */
    private function buildEntryDueAgesTable(array $zohoCustomerIds): \Illuminate\Support\Collection
    {
        $buckets = ['Aging 0-30', 'Aging 31-60', 'Aging 61-90', 'Aging 91-120', 'Aging 121-150', 'Aging 151-180', 'Aging >180'];

        if (empty($zohoCustomerIds)) {
            return collect($buckets)->map(fn ($b) => (object) ['balance_aging' => $b, 'total' => 0.0, 'count' => 0]);
        }

        $totals = array_fill_keys($buckets, 0.0);
        $counts = array_fill_keys($buckets, 0);

        DB::table('ar_ledger_entries')
            ->whereIn('zoho_customer_id', $zohoCustomerIds)
            ->where('category', 'SALES')->whereIn('entity_type', ['invoice', 'creditnote'])
            ->whereNotNull('balance')->where('balance', '!=', 0)
            ->whereNotNull('balance_aging')
            ->select('entity_type', 'balance', 'balance_aging')
            ->get()
            ->each(function ($row) use (&$totals, &$counts) {
                $bucket = $this->entryLedgerAgingLabel($row->balance_aging);
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
     * Rep-level version of CustomerController::buildDueAgesChart() —
     * invoice_documents matched by whereIn(zoho_customer_id) instead of one
     * customer.
     *
     * @return array{buckets: array<int, string>, colors: array<string, string>, months: array<int, array{label: string, shares: array<string, float>}>}
     */
    private function buildEntryDueAgesChart(array $zohoCustomerIds): array
    {
        $buckets = ['Aging 0-30', 'Aging 31-60', 'Aging 61-90', 'Aging 91-120', 'Aging 121-150', 'Aging 151-180', 'Aging 181-210', 'Aging >210'];
        $palette = ['#2f7de1', '#1c7f78', '#71b4d6', '#139653', '#c9a227', '#e08a2e', '#e0632e', '#ad4056'];
        $colors = array_combine($buckets, $palette);

        if (empty($zohoCustomerIds)) {
            return ['buckets' => $buckets, 'colors' => $colors, 'months' => []];
        }

        $today = Carbon::today();
        $start = $today->copy()->subMonthsNoOverflow(11)->startOfMonth();

        $rows = DB::table('invoice_documents')
            ->whereIn('zoho_customer_id', $zohoCustomerIds)
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
                $bucketTotals[$this->entryAgeDaysToBucket($days)] += (float) $doc->balance;
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

    /** Rep-level version of CustomerController::isHighCreditRisk(). */
    private function entryIsHighCreditRisk(?string $collectionClassification, ?string $salesClassification): bool
    {
        return $collectionClassification === 'Severe Delay'
            || ($salesClassification !== null && str_contains($salesClassification, 'Lost'));
    }

    /**
     * Rep-level version of CustomerController::buildCreditUsage() — same
     * thresholds/status logic, but over this rep's total credit limit
     * (sum across customers) and aggregate used credit (outstandingBalance,
     * already summed across the rep's customer set) instead of one
     * customer's own credit_limit. Drops the BLOCK status override
     * (isHighCreditRisk) — that's an actionable per-customer flag ("stop
     * extending credit to THIS customer"), not something that makes sense
     * averaged across a rep's whole book.
     */
    private function buildEntryCreditUsage(float $creditLimit, float $outstandingBalance): object
    {
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
     * Rep-level version of CustomerController::buildCreditDso() — same DSO
     * windows and Recommended Credit Limit formula (Ahmed Mohsen,
     * 2026-10-02), summed across this rep's whole customer set (whereIn)
     * instead of one zoho_customer_id. Payment terms days uses the most
     * common payments_due among this rep's customers (falls back to 30,
     * same default a single customer with no value set would use).
     */
    private function buildEntryCreditDso(array $zohoCustomerIds, string $dataEntryName, float $outstandingBalance, ?string $collectionClassification, ?string $salesClassification): array
    {
        if (empty($zohoCustomerIds)) {
            return [];
        }

        $today = Carbon::today();

        $firstSaleDate = DB::table('ar_ledger_entries')
            ->whereIn('zoho_customer_id', $zohoCustomerIds)
            ->where('category', 'SALES')->whereIn('entity_type', ['invoice', 'creditnote'])
            ->min('transaction_date');
        $customerStart = $firstSaleDate !== null ? Carbon::parse($firstSaleDate)->startOfDay() : $today->copy();

        $dso = function (int $months) use ($zohoCustomerIds, $today, $outstandingBalance, $customerStart) {
            $from = $today->copy()->subMonthsNoOverflow($months)->startOfDay();
            $effectiveFrom = $from->lt($customerStart) ? $customerStart->copy() : $from;
            $days = $effectiveFrom->diffInDays($today);

            if ($days < 1) {
                return null;
            }

            $creditSales = (float) DB::table('ar_ledger_entries')
                ->whereIn('zoho_customer_id', $zohoCustomerIds)
                ->whereNotNull('sales_exc_vat')
                ->whereBetween('transaction_date', [$effectiveFrom->toDateString(), $today->toDateString()])
                ->sum('sales_exc_vat');

            return $creditSales > 0 ? ($outstandingBalance / $creditSales) * $days : null;
        };

        $dso12 = $dso(12);
        $dso6 = $dso(6);
        $dso3 = $dso(3);
        $trend = 'Coming Soon';

        $salesWindowStart = $customerStart->gt($today->copy()->subMonthsNoOverflow(12))
            ? $customerStart->copy()
            : $today->copy()->subMonthsNoOverflow(12);
        $monthsElapsed = max($salesWindowStart->diffInDays($today) / 30, 1 / 30);

        $avgMonthlyNetSales = (float) DB::table('ar_ledger_entries')->whereIn('zoho_customer_id', $zohoCustomerIds)
            ->where('category', 'SALES')->whereIn('entity_type', ['invoice', 'creditnote'])
            ->where('transaction_date', '>=', $salesWindowStart->toDateString())
            ->sum('statement_amount') / $monthsElapsed;

        $paymentTermsDays = (float) (DB::table('customers')->whereNull('zoho_missing_since')
            ->where('data_entry', $dataEntryName)->whereNotNull('payments_due')
            ->selectRaw('payments_due, count(*) as cnt')
            ->groupBy('payments_due')->orderByDesc('cnt')->limit(1)->value('payments_due') ?? 30);

        $recommendedLimit = $avgMonthlyNetSales > 0
            ? $avgMonthlyNetSales * (($paymentTermsDays + 15) / 30) + $avgMonthlyNetSales * 0.20
            : null;

        $isBlocked = $this->entryIsHighCreditRisk($collectionClassification, $salesClassification);
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
        ];
    }

    /**
     * Rep-level version of CustomerController::buildItemPerformanceData() —
     * same sales_item_performance/item_owners join, summed across this
     * rep's whole customer set (whereIn) instead of one zoho_customer_id.
     * Owners ranked by Lifetime Net Sales across all of this rep's
     * customers combined, same "ranked, not fixed Owner 1..5" convention.
     *
     * @return array<int, array{rank: int, label: string, name: string, table: array<string, array<string, string>>, charts: array<string, array<int, array{label: string, value: float}>>}>
     */
    private function buildEntryItemPerformanceData(array $zohoCustomerIds): array
    {
        if (empty($zohoCustomerIds)) {
            return [];
        }

        $money = fn (float $v) => 'SAR '.number_format($v, 0);
        $baseQuery = fn () => DB::table('sales_item_performance as sip')
            ->join('item_owners as io', 'io.zoho_item_id', '=', 'sip.zoho_item_id')
            ->whereIn('sip.zoho_customer_id', $zohoCustomerIds)
            ->where('sip.transaction_type', 'Invoice')
            ->select('io.owner', 'sip.transaction_number', 'sip.zoho_item_id')
            ->selectRaw('sip.item_total as item_total, sip.item_gross_profit as item_gross_profit');

        $rowsByPeriod = [];
        foreach ($this->entryPeriodRanges() as $label => [$from, $to]) {
            $rowsByPeriod[$label] = $baseQuery()
                ->when($from !== null, fn ($q) => $q->whereBetween('sip.transaction_date', [$from->toDateString(), $to->toDateString()]))
                ->get();
        }

        $ranking = $rowsByPeriod['Lifetime']->groupBy('owner')
            ->map(fn ($rows) => (float) $rows->sum('item_total'))
            ->sortDesc();

        if ($ranking->isEmpty()) {
            return [];
        }

        $ownerStats = fn (\Illuminate\Support\Collection $rows) => [
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
                'charts' => [],
            ];
        }

        $ownerNames = $ranking->keys()->all();
        $charts = $this->buildEntryItemPerformanceCharts($zohoCustomerIds, $ownerNames);
        foreach ($owners as &$owner) {
            $owner['charts'] = $charts[$owner['name']] ?? [];
        }
        unset($owner);

        return $owners;
    }

    /**
     * @param  array<int, string>  $ownerNames
     * @return array<string, array<string, array<int, array{label: string, value: float}>>>
     */
    private function buildEntryItemPerformanceCharts(array $zohoCustomerIds, array $ownerNames): array
    {
        $start = Carbon::today()->subMonthsNoOverflow(11)->startOfMonth();

        $rows = DB::table('sales_item_performance as sip')
            ->join('item_owners as io', 'io.zoho_item_id', '=', 'sip.zoho_item_id')
            ->whereIn('sip.zoho_customer_id', $zohoCustomerIds)
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
     * Rep-level version of CustomerController::ytdRevenueMonthExtremes() —
     * same true calendar-YTD window, summed across this data entry
     * person's whole customer set (whereIn).
     *
     * @return array{0: ?array{label: string, value: float}, 1: ?array{label: string, value: float}}
     */
    private function entryYtdRevenueMonthExtremes(array $zohoCustomerIds): array
    {
        if (empty($zohoCustomerIds)) {
            return [null, null];
        }

        $today = Carbon::today();
        $rows = DB::table('ar_ledger_entries')
            ->whereIn('zoho_customer_id', $zohoCustomerIds)->where('category', 'SALES')
            ->whereIn('entity_type', ['invoice', 'creditnote'])
            ->whereBetween('transaction_date', [$today->copy()->startOfYear()->toDateString(), $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()])
            ->selectRaw('MONTH(transaction_date) as m')
            ->selectRaw('SUM(statement_amount) as total')
            ->groupBy('m')->get();

        $series = $rows->map(fn ($r) => [
            'label' => Carbon::createFromDate(null, (int) $r->m, 1)->format('M'),
            'value' => (float) $r->total,
        ])->filter(fn ($p) => $p['value'] > 0)->values();

        $firstActivity = $this->entryFirstActivityDate($zohoCustomerIds);
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

    /**
     * Rep-level version of CustomerController::buildOverviewTargets() —
     * sales/collection targets summed across every customer in this data
     * entry person's book. Same sparse-data caveat as the Customer/Sales
     * Rep versions: most customers have no target set at all, so "—" is
     * expected for most people here.
     *
     * @return array<string, ?string>
     */
    private function buildEntryOverviewTargets(array $zohoCustomerIds, string $dataEntryName): array
    {
        if (empty($zohoCustomerIds)) {
            return [];
        }

        $today = Carbon::today();
        $months = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
        $currentMonthIndex = (int) $today->format('n');
        $currentMonthAbbrev = $months[$currentMonthIndex - 1];

        $ytdSales = (float) DB::table('ar_ledger_entries')->whereIn('zoho_customer_id', $zohoCustomerIds)
            ->where('category', 'SALES')->whereIn('entity_type', ['invoice', 'creditnote'])
            ->whereBetween('transaction_date', [$today->copy()->startOfYear()->toDateString(), $today->toDateString()])
            ->sum('statement_amount');
        $mtdSales = (float) DB::table('ar_ledger_entries')->whereIn('zoho_customer_id', $zohoCustomerIds)
            ->where('category', 'SALES')->whereIn('entity_type', ['invoice', 'creditnote'])
            ->whereBetween('transaction_date', [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()])
            ->sum('statement_amount');
        $ytdCollection = (float) DB::table('ar_ledger_entries')->whereIn('zoho_customer_id', $zohoCustomerIds)
            ->where('category', 'COLLECTION')
            ->whereBetween('transaction_date', [$today->copy()->startOfYear()->toDateString(), $today->toDateString()])
            ->sum('kpi_amount');
        $mtdCollection = (float) DB::table('ar_ledger_entries')->whereIn('zoho_customer_id', $zohoCustomerIds)
            ->where('category', 'COLLECTION')
            ->whereBetween('transaction_date', [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()])
            ->sum('kpi_amount');

        $annualSalesTgt = 0.0;
        $annualCollectionTgt = 0.0;
        $monthlySalesTgts = array_fill_keys($months, 0.0);
        $monthlyCollectionTgts = array_fill_keys($months, 0.0);

        $extras = DB::table('customers')->whereNull('zoho_missing_since')->where('data_entry', $dataEntryName)->whereNotNull('extra')->pluck('extra');
        foreach ($extras as $json) {
            $data = json_decode($json, true) ?: [];
            $annualSalesTgt += (float) ($data['annual_sales_tgt'] ?? 0);
            $annualCollectionTgt += (float) ($data['annual_collection_tgt'] ?? 0);
            foreach ($months as $m) {
                $monthlySalesTgts[$m] += (float) ($data['monthly_sales_targets'][$m] ?? 0);
                $monthlyCollectionTgts[$m] += (float) ($data['monthly_collection_targets'][$m] ?? 0);
            }
        }

        $ytdSalesTarget = 0.0;
        $ytdCollectionTarget = 0.0;
        for ($i = 0; $i < $currentMonthIndex; $i++) {
            $ytdSalesTarget += $monthlySalesTgts[$months[$i]];
            $ytdCollectionTarget += $monthlyCollectionTgts[$months[$i]];
        }
        $mtdSalesTarget = $monthlySalesTgts[$currentMonthAbbrev];
        $mtdCollectionTarget = $monthlyCollectionTgts[$currentMonthAbbrev];

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

        $lastYearYtdSales = (float) DB::table('ar_ledger_entries')->whereIn('zoho_customer_id', $zohoCustomerIds)
            ->where('category', 'SALES')->whereIn('entity_type', ['invoice', 'creditnote'])
            ->whereBetween('transaction_date', [$today->copy()->subYearNoOverflow()->startOfYear()->toDateString(), $today->copy()->subYearNoOverflow()->toDateString()])
            ->sum('statement_amount');

        return [
            'ytd_sales_tgt_ach_pct' => $ytdSalesTarget > 0 ? number_format($ytdSales / $ytdSalesTarget * 100, 1).'%' : null,
            'mtd_sales_tgt_ach_pct' => $mtdSalesTarget > 0 ? number_format($mtdSales / $mtdSalesTarget * 100, 1).'%' : null,
            'ytd_collection_tgt_ach_pct' => $ytdCollectionTarget > 0 ? number_format($ytdCollection / $ytdCollectionTarget * 100, 1).'%' : null,
            'mtd_collection_tgt_ach_pct' => $mtdCollectionTarget > 0 ? number_format($mtdCollection / $mtdCollectionTarget * 100, 1).'%' : null,
            'yoy_growth_pct' => $lastYearYtdSales > 0
                ? ($ytdSales > 0 ? number_format(($ytdSales - $lastYearYtdSales) / $lastYearYtdSales * 100, 1).'%' : '-100% (Lost)')
                : null,
        ];
    }

    /** Rep-level version of CustomerController::buildSalesCharts() — rolling trailing-12-months. */
    private function buildEntrySalesCharts(array $zohoCustomerIds): array
    {
        if (empty($zohoCustomerIds)) {
            return [];
        }

        $start = Carbon::today()->subMonthsNoOverflow(11)->startOfMonth();

        $invoiceRows = DB::table('ar_ledger_entries')
            ->whereIn('zoho_customer_id', $zohoCustomerIds)->where('category', 'SALES')
            ->where('entity_type', 'invoice')
            ->where('transaction_date', '>=', $start->toDateString())
            ->selectRaw('YEAR(transaction_date) as y, MONTH(transaction_date) as m')
            ->selectRaw('SUM(statement_amount) as gross, COUNT(*) as cnt')
            ->groupBy('y', 'm')->get()
            ->keyBy(fn ($r) => sprintf('%04d-%02d', $r->y, $r->m));

        $netSalesByMonth = DB::table('ar_ledger_entries')
            ->whereIn('zoho_customer_id', $zohoCustomerIds)->where('category', 'SALES')
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
}
