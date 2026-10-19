<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

/**
 * Sales Orders list — exact copy of the Lovable portal's /sales/sales-orders (sales.sales-orders.tsx +
 * public.sales_orders_list): order date, sales order, customer name (current Customers record), status,
 * invoiced status (fetched, not shown — as theirs) and Total (BCY), server order date DESC, sales order DESC.
 * Every filter and sort is client-side, as in theirs. Their RPC reads zoho.sales_orders; ours is the same raw
 * Zoho Analytics "Sales Orders" table, zoho_raw_sales_orders, kept current by zoho:refresh.
 * The previous page stays at reports.transactions/sales.
 */
class SalesOrdersController extends Controller
{
    public function index()
    {
        $rows = DB::table('zoho_raw_sales_orders as s')
            ->leftJoin('zoho_raw_customers as c', 'c.customer_id', '=', 's.customer_id')
            ->orderByDesc('s.order_date')->orderByDesc('s.sales_order')
            ->get([DB::raw("DATE_FORMAT(s.order_date, '%Y-%m-%d') as d"), 's.sales_order as so', 'c.customer_name as c',
                's.status as st', 's.invoiced_status as inv', 's.total_bcy as amt'])
            // compact rows: [date, sales order, customer, status, invoiced status, total bcy]
            ->map(fn ($r) => [$r->d, $r->so, $r->c, $r->st, $r->inv, $r->amt === null ? null : (float) $r->amt]);

        return view('sales-orders.index', ['rows' => $rows]);
    }
}
