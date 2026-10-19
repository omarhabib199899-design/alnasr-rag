{{-- Sales — their CustomerSalesKpis + CustomerSalesTargetVsActual + CustomerSalesCharts; on the Entity 360 pages ($isEntity)
     EntitySalesKpis / EntitySalesTargetVsActual / EntitySalesCharts: no Average Invoice Value / Largest Invoice, portfolio wording. --}}
@php
    use App\Support\C360Format as F;
    $ent = $isEntity ?? false;
    $who = $ent ? "this portfolio" : "this customer";
    $first = $salesTarget[0] ?? null;
    $targetNotes = [
        '* Current month — actual data through '.e(F::dmy($first['as_of_date'] ?? null)).' (incomplete month, not extrapolated). Future months show —.',
        'Total Actual Sales = YTD Net Sales. Total Ach % uses elapsed months with a positive target; Total Balance uses elapsed months with a target.',
    ];
    if (($first['missing_target_months'] ?? 0) > 0) {
        $targetNotes[] = 'Targets are missing for '.$first['missing_target_months'].' of 12 months — totals for Ach % and Balance are based on eligible months only.';
    }
@endphp
<div class="c3-stack">
    @include('customers.c360.matrix', [
        'icon' => 'file-text', 'title' => 'Sales', 'data' => $salesKpis, 'empty' => "No sales KPI data for {$who}.",
        'rows' => array_merge([
            ['Net Sales', fn ($p) => F::money($p['net_sales'])],
            ['Gross Sales', fn ($p) => F::money($p['gross_sales'])],
            ['Sales Average', fn ($p) => F::money($p['sales_average'])],
            ['Sales Median', fn ($p) => F::money($p['sales_median'])],
            ['Revenue Volatility %', fn ($p) => F::pct1($p['revenue_volatility_pct'])],
            ['Revenue Contribution %', fn ($p) => F::pct2($p['revenue_contribution_pct'])],
            ['Revenue Rank — Company', fn ($p) => F::rank($p['revenue_rank'], $p['revenue_rank_total'])],
            ['Invoice Count', fn ($p) => F::int($p['invoice_count'])],
        ], $ent ? [] : [
            ['Average Invoice Value', fn ($p) => F::money($p['average_invoice_value'])],
            ['Largest Invoice', fn ($p) => F::money($p['largest_invoice'])],
        ]),
    ])

    @include('customers.c360.target-table', [
        'icon' => 'target', 'title' => 'Sales Target vs Actual — '.($first['report_year'] ?? ''), 'rows' => $salesTarget,
        'totalLabel' => 'Total', 'notes' => $targetNotes, 'empty' => "No sales target data for {$who}.",
        'defs' => [
            ['Sales TGT', fn ($r) => F::money($r['sales_tgt'])],
            ['Actual Sales', fn ($r) => F::money($r['actual_sales'])],
            ['Ach %', fn ($r) => F::pct2($r['ach_pct'])],
            ['Balance', fn ($r) => F::money($r['balance'])],
        ],
    ])

    @include('customers.c360.line-charts', [
        'months' => $salesMonthly, 'cols' => 'c3col', 'empty' => "No chart data for {$who}.",
        'charts' => [
            ['id' => 'sales_net', 'title' => 'Net Sales — Last 12 Months', 'icon' => 'trending-up', 'key' => 'net_sales', 'kind' => 'money', 'zero' => true,
                'tip' => fn ($r) => 'SAR '.F::money($r['net_sales']), 'lead' => null],
            ['id' => 'sales_avg', 'title' => 'Sales Average — Last 12 Months', 'icon' => 'line-chart', 'key' => 'sales_average_daily', 'kind' => 'money', 'zero' => true,
                'tip' => fn ($r) => $r['sales_average_daily'] === null ? F::DASH : 'SAR '.F::money($r['sales_average_daily']),
                'lead' => 'Daily Sales Average within each month (nonzero Net Sales days only).'],
            ['id' => 'sales_inv', 'title' => 'Invoice Count — Last 12 Months', 'icon' => 'hash', 'key' => 'invoice_count', 'kind' => 'int', 'zero' => false,
                'tip' => fn ($r) => F::int($r['invoice_count']).' invoices', 'lead' => null],
        ],
    ])
</div>
