<?php

namespace App\Http\Controllers;

use App\Services\Customer360\Customer360Data;
use App\Services\Customer360\Customer360Insights;
use App\Services\Customer360\Entity360Data;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sales Reps / Sales Owners / Data Entry — exact copy of the Lovable portal's CustomerDirectory list pages and
 * Entity360Page (same 10 tabs as Customer 360, entity variants of Sales, Collection and Sales Orders, Overview
 * distributions, Insights = Classifications + Customer Actions). The previous pages stay at …/legacy.
 */
class Entity360Controller extends Controller
{
    /** kind => scope field, route prefix, directory label, page title, back label. */
    public const KINDS = [
        'salesRep' => ['rep_name', 'sales-reps', 'Sales Reps', 'Sales Rep 360', 'Back to Sales Reps'],
        'salesOwner' => ['sales_owner', 'sales-owners', 'Sales Owners', 'Sales Owner 360', 'Back to Sales Owners'],
        'dataEntry' => ['data_entry', 'data-entry', 'Data Entry', 'Data Entry 360', 'Back to Data Entry'],
        'management' => ['management', 'management', 'Management', 'Management 360', 'Back to Management'],
    ];

    /** Their 10 tabs, in order (Due Ages merged into Accounts Receivable); same permission keys as Customer 360. */
    private const TABS = [
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
    ];

    /** The scope fields shown as links in the drawers, per page (their relatedFieldsFor). */
    private const RELATED = [
        'data_entry' => ['rep_name', 'sales_owner'],
        'sales_owner' => ['rep_name', 'data_entry'],
        'rep_name' => ['sales_owner', 'data_entry'],
        'management' => ['data_entry', 'rep_name', 'sales_owner'],
    ];

    public function index(Request $request)
    {
        $kind = self::kindOf($request);
        [$scope, $prefix, $title] = self::KINDS[$kind];

        return view('entities.index', ['kind' => $kind, 'prefix' => $prefix, 'title' => $title, 'rows' => Entity360Data::directory($scope)]);
    }

    public function show(Request $request, string $name)
    {
        $kind = self::kindOf($request);
        [$scope, $prefix] = self::KINDS[$kind];
        $data = new Customer360Data($scope, $name);
        $entity = new Entity360Data($scope, $name);
        $tabs = collect(self::TABS)->filter(fn ($t) => $request->user()->hasPermission("customers.tab.{$t[2]}"))->all();
        $has = fn (string $t) => array_key_exists($t, $tabs);

        return view('entities.show360', [
            'kind' => $kind, 'k' => self::KINDS[$kind], 'name' => $name, 'scope' => $scope, 'prefix' => $prefix,
            'related' => self::RELATED[$scope],
            'tabs' => $tabs,
            'activeTab' => $has($t = (string) $request->query('tab')) ? $t : array_key_first($tabs),
            'isEntity' => true,
            'distributions' => $has('overview') ? $entity->distributions() : [],
            'activeCustomers' => $has('overview') ? $entity->activeCustomers() : null,
            'targetAchievement' => $has('overview') ? $data->targetAchievement() : [],
            'salesKpis' => $has('sales') ? $data->portfolioSalesKpis() : [],
            'salesTarget' => $has('sales') ? $data->targetVsActual('sales') : [],
            'salesMonthly' => $has('sales') ? $data->salesMonthly() : [],
            'creditNoteKpis' => $has('credit_note') ? $data->creditNoteKpis() : [],
            'creditNoteMonthly' => $has('credit_note') ? $data->creditNoteMonthly() : [],
            'soSummary' => $has('sales_orders') ? $data->soStatusSummary() : [],
            'soOrders' => $has('sales_orders') ? $data->soEntityOrders() : [],
            'profitabilityKpis' => $has('profitability') ? $data->profitabilityKpis() : [],
            'profitabilityMonthly' => $has('profitability') ? $data->profitabilityMonthly() : [],
            'collectionKpis' => $has('collection') ? $data->collectionKpis() : [],
            'collectionTarget' => $has('collection') ? $data->targetVsActual('collection') : [],
            'collectionMonthly' => $has('collection') ? $data->collectionMonthly() : [],
            'arOverview' => $has('ar') ? $data->arOverview() : null,
            'arAging' => $has('ar') ? $data->arAging() : [],
            'creditLimit' => $has('credit_limit') ? $data->creditLimitOverview() : null,
            'creditDso' => $has('credit_limit') ? $data->creditDso() : [],
            'itemOwnerKpis' => $has('item_performance') ? $data->itemOwnerKpis() : [],
            'itemRankings' => $has('item_performance') ? $data->itemRankings() : [],
            'itemContribution' => $has('item_performance') ? $data->itemContribution() : [],
            'classificationCounts' => $has('insights') ? $entity->classificationCounts() : [],
            'actionCounts' => $has('insights') ? $entity->actionCounts() : [],
            'snapshotStatus' => $has('insights') ? Entity360Data::snapshotStatus() : null,
        ]);
    }

    /** salesRep / salesOwner / dataEntry, from the route name's prefix (sales-reps.show → salesRep). */
    private static function kindOf(Request $request): string
    {
        $prefix = explode('.', (string) $request->route()->getName())[0];
        foreach (self::KINDS as $kind => $k) {
            if ($k[1] === $prefix) {
                return $kind;
            }
        }
        abort(404);
    }

    private function scopeOf(Request $request): string
    {
        return self::KINDS[self::kindOf($request)][0];
    }

    /** Rows behind the Outstanding / Overdue Invoices Count KPIs (AR tab drawer). */
    public function arDetails(Request $request, string $name): JsonResponse
    {
        $kind = $request->query('kind') === 'overdue' ? 'overdue' : 'outstanding';

        return response()->json((new Customer360Data($this->scopeOf($request), $name))->arInvoiceDetails($kind));
    }

    /** ClassificationCustomersDrawer. */
    public function classificationCustomers(Request $request, string $name): JsonResponse
    {
        $group = (string) $request->query('group');
        if (! in_array($group, Entity360Data::CLASSIFICATION_GROUPS, true)) {
            return response()->json([]);
        }

        return response()->json((new Entity360Data($this->scopeOf($request), $name))->classificationCustomers($group, mb_substr((string) $request->query('value'), 0, 500)));
    }

    /** PortfolioActionCustomersDrawer — the customers of one action in the given generation. */
    public function actionCustomers(Request $request, string $name): JsonResponse
    {
        $action = (string) $request->query('action');
        $gen = (int) $request->query('generation');
        if (! preg_match('/^[a-z_]{1,64}$/', $action) || $gen <= 0 || ! DB::table('insights_action_registry')->where('action_code', $action)->exists()) {
            return response()->json(['generation_available' => true, 'rows' => []]);
        }

        return response()->json((new Entity360Data($this->scopeOf($request), $name))->actionCustomers($action, $gen));
    }

    /**
     * Live action detail for up to 25 customers of the drawer's current page (their ActionDetail = the customer's own
     * Insights card for that one action). Returns customer id => rendered HTML, or null when the action is no longer present.
     */
    public function actionDetail(Request $request, string $name): JsonResponse
    {
        $action = (string) $request->query('action');
        $category = DB::table('insights_action_registry')->where('action_code', $action)->value('category');
        $ids = array_slice(array_filter(explode(',', (string) $request->query('customers')), fn ($v) => preg_match('/^\d+$/', $v)), 0, 25);
        if ($category === null || ! $ids) {
            return response()->json([]);
        }
        // Only customers of this portfolio.
        $ids = DB::table('zoho_raw_customers')->whereIn('customer_id', $ids)->where($this->scopeOf($request), $name)->pluck('customer_id')->map(fn ($v) => (string) $v)->all();
        $local = DB::table('customers')->whereIn('zoho_contact_id', $ids ?: ['__none__'])->pluck('id', 'zoho_contact_id');

        $out = [];
        foreach ($ids as $id) {
            $engine = new Customer360Insights($id);
            $ins = ['sales' => [], 'items' => [], 'expiry' => null, 'credit_notes' => [], 'sales_orders' => [], 'profitability' => [], 'financial' => null, 'documentation' => []];
            $pick = fn (array $rows) => array_values(array_filter($rows, fn ($r) => $r['action_code'] === $action));
            switch ($category) {
                case 'sales': $ins['sales'] = $pick($engine->sales()); break;
                case 'items':
                    if ($action === 'products_approaching_expiry') {
                        $ins['expiry'] = $engine->expiry();
                    } else {
                        $ins['items'] = $pick($engine->items());
                    }
                    break;
                case 'credit_notes': $ins['credit_notes'] = $pick($engine->creditNotes()); break;
                case 'sales_orders': $ins['sales_orders'] = $pick($engine->salesOrders()); break;
                case 'profitability': $ins['profitability'] = $pick($engine->profitability()); break;
                case 'financial_health':
                    $fin = $engine->financialHealth();
                    $ins['financial'] = ($fin && ($action === 'monthly_collection_required' || $fin['has_unapplied_credit'])) ? $fin : null;
                    break;
                case 'documentation': $ins['documentation'] = $pick($engine->documentation()); break;
            }
            $present = $ins['sales'] || $ins['items'] || $ins['expiry'] || $ins['credit_notes'] || $ins['sales_orders'] || $ins['profitability'] || $ins['financial'] || $ins['documentation'];
            $out[$id] = $present ? view('customers.c360.tab-insights', [
                'insights' => $ins, 'onlyAction' => $action,
                'insightUrls' => isset($local[$id]) ? [route('customers.show360.insight-items', $local[$id]), route('customers.show360.expiry-items', $local[$id])] : null,
            ])->render() : null;
        }

        return response()->json($out);
    }
}
