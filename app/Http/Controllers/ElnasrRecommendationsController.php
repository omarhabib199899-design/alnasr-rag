<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Elnasr Recommendations" — a customer-prioritization view built from a set
 * of Zoho Analytics Query Tables the manager exports directly (computed
 * segmentation/recommendation tables, not raw Zoho records — see the
 * ImportCustomerSalesRecommendations, ImportCustomerCreditUsage, and
 * ImportCustomerDocumentInsights commands for each source file). One row per customer, combining:
 * the five segment classifications + synthesized recommendation
 * (customer_sales_recommendations), credit exposure (customer_credit_usage),
 * relationship tenure (customer_sales_behavior.historical_first_purchase),
 * and a missing-documents count (customer_document_insights).
 */
class ElnasrRecommendationsController extends Controller
{
    public function index(Request $request)
    {
        $management = $request->query('management');
        $repName = $request->query('rep_name');
        $customerName = $request->query('customer_name');
        $salesOwner = $request->query('sales_owner');
        $creditStatus = $request->query('credit_status');
        $priority = $request->query('priority');
        $sort = $request->query('sort', 'revenue_rank');

        $documentIssueCounts = DB::table('customer_document_insights')
            ->select('zoho_customer_id')
            ->selectRaw('COUNT(*) as issue_count')
            ->groupBy('zoho_customer_id');

        // customer_sales_behavior.historical_first_purchase comes from a
        // manually-exported Zoho Analytics view that turned out to compute
        // this field over some bounded recent window rather than true
        // all-time history (confirmed live 2026-09-23: showed a customer's
        // "first purchase" as 2026-03-03 when their real first invoice,
        // #49914, was dated 2024-11-02 — verified against real transaction
        // data below). Computing it ourselves from sales_item_performance
        // (the live-synced, complete transaction history) sidesteps that
        // bug entirely instead of trusting the manually-exported field.
        $firstPurchaseByCustomer = DB::table('sales_item_performance')
            ->select('zoho_customer_id')
            ->selectRaw('MIN(transaction_date) as first_purchase_date')
            ->groupBy('zoho_customer_id');

        $query = DB::table('customer_sales_recommendations as r')
            ->leftJoin('customer_credit_usage as c', 'c.zoho_customer_id', '=', 'r.zoho_customer_id')
            ->leftJoin('customer_sales_behavior as b', 'b.zoho_customer_id', '=', 'r.zoho_customer_id')
            ->leftJoin('customers as cu', 'cu.zoho_contact_id', '=', 'r.zoho_customer_id')
            ->leftJoinSub($documentIssueCounts, 'd', 'd.zoho_customer_id', '=', 'r.zoho_customer_id')
            ->leftJoinSub($firstPurchaseByCustomer, 'fp', 'fp.zoho_customer_id', '=', 'r.zoho_customer_id')
            ->select([
                'r.*',
                'c.management', 'c.rep_name', 'c.credit_limit', 'c.used_credit',
                'c.credit_utilization_pct', 'c.remaining_credit', 'c.credit_status',
                'fp.first_purchase_date as historical_first_purchase',
                'b.historical_last_purchase',
                'b.sales_growth_why', 'b.buying_rhythm_why', 'b.aov_growth_why',
                'cu.sales_owner',
                'd.issue_count as document_issue_count',
            ]);

        if ($management !== null && $management !== '') {
            $query->where('c.management', $management);
        }
        if ($repName !== null && $repName !== '') {
            $query->where('c.rep_name', $repName);
        }
        if ($customerName !== null && $customerName !== '') {
            $query->where('r.customer_name', $customerName);
        }
        if ($salesOwner !== null && $salesOwner !== '') {
            $query->where('cu.sales_owner', $salesOwner);
        }
        if ($creditStatus !== null && $creditStatus !== '') {
            $query->where('c.credit_status', $creditStatus);
        }
        if ($priority !== null && $priority !== '') {
            $query->where('r.priority_level', $priority);
        }

        match ($sort) {
            'sales_rank' => $query->orderByRaw('r.sales_rank IS NULL, r.sales_rank ASC'),
            'days_since_last_purchase' => $query->orderByRaw('r.days_since_last_purchase IS NULL, r.days_since_last_purchase DESC'),
            'tenure' => $query->orderByRaw('fp.first_purchase_date IS NULL, fp.first_purchase_date ASC'),
            'priority' => $query->orderByRaw('r.priority_level IS NULL, r.priority_level ASC'),
            default => $query->orderByRaw('r.revenue_rank IS NULL, r.revenue_rank ASC'),
        };

        $customers = $query->paginate(8)->withQueryString();

        $customerIds = collect($customers->items())->pluck('zoho_customer_id')->all();
        $monthlyStatsByCustomer = DB::table('sales_item_monthly_performance')
            ->whereIn('zoho_customer_id', $customerIds)
            ->select('zoho_customer_id', 'year', 'month')
            ->selectRaw('SUM(item_total) as sales')
            ->selectRaw('SUM(item_gross_profit) as profit')
            ->groupBy('zoho_customer_id', 'year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get()
            ->groupBy('zoho_customer_id');

        $documentInsightsByCustomer = DB::table('customer_document_insights')
            ->whereIn('zoho_customer_id', $customerIds)
            ->orderBy('id')
            ->get()
            ->groupBy('zoho_customer_id');

        $invoiceProfitByCustomer = DB::table('sales_item_performance')
            ->whereIn('zoho_customer_id', $customerIds)
            ->where('transaction_type', 'Invoice')
            ->select('zoho_customer_id', 'transaction_number', 'transaction_date')
            ->selectRaw('SUM(item_total) as amount')
            ->selectRaw('SUM(item_gross_profit) as gross_profit')
            ->groupBy('zoho_customer_id', 'transaction_number', 'transaction_date')
            ->orderByDesc('transaction_date')
            ->get()
            ->groupBy('zoho_customer_id');

        $agingByCustomer = DB::table('ar_ledger_entries')
            ->whereIn('zoho_customer_id', $customerIds)
            ->whereNotNull('balance_aging')
            ->where('balance_aging', '!=', '')
            ->select('zoho_customer_id', 'balance_aging')
            ->selectRaw('SUM(balance) as total')
            ->groupBy('zoho_customer_id', 'balance_aging')
            ->orderBy('balance_aging')
            ->get()
            ->groupBy('zoho_customer_id');

        $dueInvoicesByCustomer = DB::table('ar_ledger_entries')
            ->whereIn('zoho_customer_id', $customerIds)
            ->whereNotNull('balance_aging')
            ->where('balance_aging', '!=', '')
            ->select('zoho_customer_id', 'transaction_number', 'transaction_date', 'inv_balance_due_date', 'balance', 'balance_aging')
            ->orderBy('inv_balance_due_date')
            ->get()
            ->groupBy('zoho_customer_id');

        $collectionsByCustomer = DB::table('ar_ledger_entries')
            ->whereIn('zoho_customer_id', $customerIds)
            ->where('category', 'COLLECTION')
            ->selectRaw('zoho_customer_id, YEAR(transaction_date) as year, MONTH(transaction_date) as month')
            ->selectRaw('SUM(kpi_amount) as amount')
            ->groupBy('zoho_customer_id', DB::raw('YEAR(transaction_date)'), DB::raw('MONTH(transaction_date)'))
            ->orderBy('year')
            ->orderBy('month')
            ->get()
            ->groupBy('zoho_customer_id');

        foreach ($customers as $c) {
            $c->monthlyStats = $monthlyStatsByCustomer->get($c->zoho_customer_id, collect());
            $c->documentInsights = $documentInsightsByCustomer->get($c->zoho_customer_id, collect());
            $c->invoiceProfit = $invoiceProfitByCustomer->get($c->zoho_customer_id, collect());
            $c->aging = $agingByCustomer->get($c->zoho_customer_id, collect());
            $c->dueInvoices = $dueInvoicesByCustomer->get($c->zoho_customer_id, collect());
            $c->collections = $collectionsByCustomer->get($c->zoho_customer_id, collect());
        }

        $repSummary = null;
        $selectionMade = ($repName !== null && $repName !== '') || ($customerName !== null && $customerName !== '');
        if ($selectionMade) {
            $repCustomerIds = DB::table('customer_sales_recommendations as r')
                ->leftJoin('customer_credit_usage as c', 'c.zoho_customer_id', '=', 'r.zoho_customer_id')
                ->leftJoin('customers as cu', 'cu.zoho_contact_id', '=', 'r.zoho_customer_id')
                ->when($repName, fn ($q) => $q->where('c.rep_name', $repName))
                ->when($management, fn ($q) => $q->where('c.management', $management))
                ->when($customerName, fn ($q) => $q->where('r.customer_name', $customerName))
                ->when($salesOwner, fn ($q) => $q->where('cu.sales_owner', $salesOwner))
                ->when($creditStatus, fn ($q) => $q->where('c.credit_status', $creditStatus))
                ->pluck('r.zoho_customer_id')
                ->all();

            if (! empty($repCustomerIds)) {
                $repMonthlySales = DB::table('sales_item_monthly_performance')
                    ->whereIn('zoho_customer_id', $repCustomerIds)
                    ->select('year', 'month')
                    ->selectRaw('SUM(item_total) as sales')
                    ->selectRaw('SUM(item_gross_profit) as profit')
                    ->groupBy('year', 'month')
                    ->orderBy('year')
                    ->orderBy('month')
                    ->get()
                    ->keyBy(fn ($row) => "{$row->year}-{$row->month}");

                $repMonthlyCollections = DB::table('ar_ledger_entries')
                    ->whereIn('zoho_customer_id', $repCustomerIds)
                    ->where('category', 'COLLECTION')
                    ->selectRaw('YEAR(transaction_date) as year, MONTH(transaction_date) as month')
                    ->selectRaw('SUM(kpi_amount) as amount')
                    ->groupBy(DB::raw('YEAR(transaction_date)'), DB::raw('MONTH(transaction_date)'))
                    ->get()
                    ->keyBy(fn ($row) => "{$row->year}-{$row->month}");

                $repItemCounts = DB::table('sales_item_performance')
                    ->whereIn('zoho_customer_id', $repCustomerIds)
                    ->where('transaction_type', 'Invoice')
                    ->selectRaw('YEAR(transaction_date) as year, MONTH(transaction_date) as month')
                    ->selectRaw('COUNT(DISTINCT zoho_item_id) as item_count')
                    ->groupBy(DB::raw('YEAR(transaction_date)'), DB::raw('MONTH(transaction_date)'))
                    ->get()
                    ->keyBy(fn ($row) => "{$row->year}-{$row->month}");

                $repCustomerCounts = DB::table('sales_item_performance')
                    ->whereIn('zoho_customer_id', $repCustomerIds)
                    ->where('transaction_type', 'Invoice')
                    ->selectRaw('YEAR(transaction_date) as year, MONTH(transaction_date) as month')
                    ->selectRaw('COUNT(DISTINCT zoho_customer_id) as customer_count')
                    ->groupBy(DB::raw('YEAR(transaction_date)'), DB::raw('MONTH(transaction_date)'))
                    ->get()
                    ->keyBy(fn ($row) => "{$row->year}-{$row->month}");

                $months = $repMonthlySales->keys()
                    ->merge($repMonthlyCollections->keys())
                    ->merge($repItemCounts->keys())
                    ->merge($repCustomerCounts->keys())
                    ->unique()->sort();

                $repAging = DB::table('ar_ledger_entries')
                    ->whereIn('zoho_customer_id', $repCustomerIds)
                    ->whereNotNull('balance_aging')
                    ->where('balance_aging', '!=', '')
                    ->select('balance_aging')
                    ->selectRaw('SUM(balance) as total')
                    ->groupBy('balance_aging')
                    ->orderBy('balance_aging')
                    ->get();

                $repAgingCustomerCount = DB::table('ar_ledger_entries')
                    ->whereIn('zoho_customer_id', $repCustomerIds)
                    ->whereNotNull('balance_aging')
                    ->where('balance_aging', '!=', '')
                    ->select('balance_aging')
                    ->selectRaw('COUNT(DISTINCT zoho_customer_id) as customer_count')
                    ->groupBy('balance_aging')
                    ->orderBy('balance_aging')
                    ->get();

                $repSummary = [
                    'rep_name' => $repName ?: $customerName,
                    'management' => $management,
                    'customer_count' => count($repCustomerIds),
                    'has_data' => $months->isNotEmpty(),
                    'months' => $months->map(function ($key) use ($repMonthlySales, $repMonthlyCollections, $repItemCounts, $repCustomerCounts) {
                        $sales = $repMonthlySales->get($key);
                        $collection = $repMonthlyCollections->get($key);
                        $items = $repItemCounts->get($key);
                        $custCount = $repCustomerCounts->get($key);

                        return (object) [
                            'label' => $key,
                            'sales' => $sales->sales ?? 0,
                            'profit' => $sales->profit ?? 0,
                            'collected' => $collection->amount ?? 0,
                            'item_count' => $items->item_count ?? 0,
                            'customer_count' => $custCount->customer_count ?? 0,
                        ];
                    })->values(),
                    'total_sales' => $repMonthlySales->sum('sales'),
                    'total_profit' => $repMonthlySales->sum('profit'),
                    'total_collected' => $repMonthlyCollections->sum('amount'),
                    'aging' => $repAging,
                    'total_aging' => $repAging->sum('total'),
                    'aging_customer_count' => $repAgingCustomerCount,
                ];
            }
        }

        $customerOptions = DB::table('customer_sales_recommendations')->whereNotNull('customer_name')->where('customer_name', '!=', '')->distinct()->orderBy('customer_name')->pluck('customer_name');
        $customerRepMap = DB::table('customer_sales_recommendations as r')
            ->leftJoin('customer_credit_usage as c', 'c.zoho_customer_id', '=', 'r.zoho_customer_id')
            ->whereNotNull('r.customer_name')
            ->where('r.customer_name', '!=', '')
            ->pluck('c.rep_name', 'r.customer_name');
        $managementOptions = DB::table('customer_credit_usage')->whereNotNull('management')->distinct()->orderBy('management')->pluck('management');
        $repOptions = DB::table('customer_credit_usage')->whereNotNull('rep_name')->distinct()->orderBy('rep_name')->pluck('rep_name');
        $salesOwnerOptions = DB::table('customers')->whereNull('zoho_missing_since')->whereNotNull('sales_owner')->where('sales_owner', '!=', '')->distinct()->orderBy('sales_owner')->pluck('sales_owner');
        $creditStatusOptions = DB::table('customer_credit_usage')->whereNotNull('credit_status')->distinct()->orderBy('credit_status')->pluck('credit_status');

        $priorityCounts = DB::table('customer_sales_recommendations')
            ->whereNotNull('priority_level')
            ->selectRaw('priority_level, COUNT(*) as count')
            ->groupBy('priority_level')
            ->orderBy('priority_level')
            ->pluck('count', 'priority_level');

        return view('elnasr-recommendations.index', [
            'customers' => $customers,
            'repSummary' => $repSummary,
            'selectionMade' => $selectionMade,
            'lastUpdated' => DB::table('customer_sales_recommendations')->max('updated_at'),
            'management' => $management,
            'repName' => $repName,
            'customerName' => $customerName,
            'salesOwner' => $salesOwner,
            'creditStatus' => $creditStatus,
            'priority' => $priority,
            'sort' => $sort,
            'customerOptions' => $customerOptions,
            'customerRepMap' => $customerRepMap,
            'managementOptions' => $managementOptions,
            'repOptions' => $repOptions,
            'salesOwnerOptions' => $salesOwnerOptions,
            'creditStatusOptions' => $creditStatusOptions,
            'priorityCounts' => $priorityCounts,
            'totalCount' => DB::table('customer_sales_recommendations')->count(),
        ]);
    }
}
