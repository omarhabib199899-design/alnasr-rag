<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerChangeLog;
use App\Models\CustomerContactPerson;
use App\Services\Customer360\Customer360Data;
use App\Services\Customer360\Customer360Insights;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer 360 rebuilt as an exact copy of the Al-Nasr Lovable portal's
 * page (design, formulas, data sources — manager directive 2026-10-19),
 * served at /customers/{customer}/360 alongside the existing page until it
 * is approved. All numbers come from Customer360Data (their functions,
 * ported); our own extra tabs (General, Change History) are kept.
 */
class Customer360Controller extends Controller
{
    /** Tab id => [label, Lucide icon, existing customers.tab.* permission key]. Their 10 tabs first, then ours. */
    public const TABS = [
        'overview' => ['Overview', 'layout-dashboard', 'overview'],
        'sales' => ['Sales', 'file-text', 'sales'],
        'credit_note' => ['Credit Note', 'file-minus', 'return'],
        'sales_orders' => ['Sales Orders', 'clipboard-list', 'sales_orders'],
        'profitability' => ['Profitability', 'trending-up', 'profitability'],
        'collection' => ['Collection', 'clock', 'collection'],
        'ar' => ['Accounts Receivable', 'receipt', 'ar'],
        'credit_limit' => ['Credit Limit', 'badge-check', 'credit_limit'],
        'item_performance' => ['Item Performance', 'package', 'item_performance'],
        'insights' => ['Insights', 'lightbulb', 'insights'],
        'general' => ['General', 'info', 'general'],
        'history' => ['Change History', 'history', 'history'],
    ];

    /** ?tab= values from links into the previous customer page → the matching tab here. */
    private const TAB_ALIASES = ['return' => 'credit_note', 'due_ages' => 'ar'];

    /** Tabs not ported yet — hidden rather than shown empty. */
    private const PENDING_TABS = [];

    public function show(Request $request, Customer $customer)
    {
        $data = new Customer360Data('customer_id', (string) $customer->zoho_contact_id);
        $tabs = collect(self::TABS)
            ->except(self::PENDING_TABS)
            ->filter(fn ($t) => $request->user()->hasPermission("customers.tab.{$t[2]}"))->all();

        $customerExtraKeys = ['email', 'phone', 'mobile', 'website', 'designation', 'department', 'source', 'sales_channel',
            'vat_reg_no', 'tax_reg_no', 'pricebook_name', 'notes', 'outstanding_payable_amount', 'unused_credits_receivable_amount'];

        return view('customers.show360', [
            'customer' => $customer,
            'tabs' => $tabs,
            'activeTab' => array_key_exists($tab = self::TAB_ALIASES[$request->query('tab')] ?? $request->query('tab'), $tabs) ? $tab : array_key_first($tabs),
            'today' => $data->today(),
            'overview' => $data->overview(),
            'targetAchievement' => $data->targetAchievement(),
            'salesKpis' => $data->salesKpis(),
            'salesTarget' => $data->targetVsActual('sales'),
            'salesMonthly' => $data->salesMonthly(),
            'creditNoteKpis' => $data->creditNoteKpis(),
            'creditNoteMonthly' => $data->creditNoteMonthly(),
            'soSummary' => $data->soStatusSummary(),
            'soLines' => $data->soCustomerLines(),
            'profitabilityKpis' => $data->profitabilityKpis(),
            'profitabilityMonthly' => $data->profitabilityMonthly(),
            'collectionKpis' => $data->collectionKpis(),
            'collectionTarget' => $data->targetVsActual('collection'),
            'collectionMonthly' => $data->collectionMonthly(),
            'arOverview' => $data->arOverview(),
            'arAging' => $data->arAging(),
            'creditLimit' => $data->creditLimitOverview(),
            'creditDso' => $data->creditDso(),
            'itemOwnerKpis' => $data->itemOwnerKpis(),
            'itemRankings' => $data->itemRankings(),
            'itemContribution' => $data->itemContribution(),
            'insights' => array_key_exists('insights', $tabs) ? (new Customer360Insights((string) $customer->zoho_contact_id))->all() : [],
            // Our own tabs (kept — additive rule). Last Sales Order / Last Payment were on our old Overview; they now
            // read the same sources as the rest of this page.
            'lastSalesOrderDate' => DB::table('zoho_raw_sales_orders')->where('customer_id', $customer->zoho_contact_id)->max('order_date'),
            'lastPaymentDate' => Schema::hasTable('zoho_ar_sales')
                ? DB::table('zoho_ar_sales')->where('customer_id', $customer->zoho_contact_id)->where('category', 'COLLECTION')->max('transaction_date')
                : null,
            'contactPersons' => CustomerContactPerson::where('zoho_customer_id', $customer->zoho_contact_id)->orderBy('email')->get(),
            'customerExtra' => collect($customer->live_contact_data ?? [])->only($customerExtraKeys)
                ->filter(fn ($v) => $v !== null && $v !== '' && $v !== [])->all(),
            'changeLogs' => CustomerChangeLog::where('customer_id', $customer->id)->orderByDesc('changed_at')->paginate(50, ['*'], 'history_page'),
        ]);
    }

    /** Items behind one item-performance insight (Insights tab drawer). */
    public function insightItems(Request $request, Customer $customer): JsonResponse
    {
        $action = (string) $request->query('action');
        if (! in_array($action, ['fewer_items', 'stopped_items', 'qty_dropped', 'different_items'], true)) {
            return response()->json([]);
        }

        return response()->json((new Customer360Insights((string) $customer->zoho_contact_id))->itemInsightItems($action));
    }

    /** Batches behind "Products Approaching Expiry" (Insights tab drawer). */
    public function expiryItems(Customer $customer): JsonResponse
    {
        return response()->json((new Customer360Insights((string) $customer->zoho_contact_id))->expiryItems());
    }

    /** Rows behind the Outstanding / Overdue Invoices Count KPIs (AR tab drawer). */
    public function arDetails(Request $request, Customer $customer): JsonResponse
    {
        $kind = $request->query('kind') === 'overdue' ? 'overdue' : 'outstanding';

        return response()->json((new Customer360Data('customer_id', (string) $customer->zoho_contact_id))->arInvoiceDetails($kind));
    }
}
