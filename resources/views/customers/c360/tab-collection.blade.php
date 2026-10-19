{{-- Collection — their CollectionKpis + CollectionTargetVsActual + CollectionCharts. Customer variant: 11 KPIs, 4 charts in 2 columns;
     entity variant ($isEntity): the first 7 KPIs (no payment KPIs or their note), 3 charts in 3 columns. --}}
@php
    use App\Support\C360Format as F;
    $ent = $isEntity ?? false;
    $first = $collectionTarget[0] ?? null;
    $targetNotes = [
        '* Current month — actual data through '.e(F::dmy($first['as_of_date'] ?? null)).' (incomplete month, not extrapolated). Future months show —.',
        'Total Actual Collection = YTD Collection. Total Ach % uses elapsed months with a positive target; Total Balance uses elapsed months with a target.',
    ];
    if (($first['missing_target_months'] ?? 0) > 0) {
        $targetNotes[] = 'Targets are missing for '.$first['missing_target_months'].' of 12 months — totals for Ach % and Balance are based on eligible months only.';
    }
@endphp
<div class="c3-stack">
    @include('customers.c360.matrix', [
        'icon' => 'wallet', 'title' => 'Collection', 'data' => $collectionKpis, 'empty' => 'No collection data available.',
        'notes' => array_slice([
            'Collection = signed amount of all COLLECTION transactions (customer payments, invoice payments, payment refunds and credit note refunds; refunds reduce the total). Collection Ratio vs Sales = Collection ÷ Net Sales of the same period (no invoice matching). Average, Median and Volatility use the same buckets as the Sales tab. Rank compares against the same entity type (all customers on Customer 360) with Collection above zero.',
            'Payment KPIs use actual payments only (customer payments and invoice payments; refunds excluded). Largest Payment Value = largest total of payments received on a single day. Average Payment Interval = average days between consecutive payment dates within the period.',
        ], 0, $ent ? 1 : 2),
        'rows' => array_slice([
            ['Collection', fn ($p) => F::money($p['net_collection'])],
            ['Collection Ratio vs Sales', fn ($p) => F::pct2($p['collection_ratio_pct'])],
            ['Collection Average', fn ($p) => F::money($p['collection_average'])],
            ['Collection Median', fn ($p) => F::money($p['collection_median'])],
            ['Collection Volatility %', fn ($p) => F::pct1($p['collection_volatility_pct'])],
            ['Collection Contribution %', fn ($p) => F::pct2($p['collection_contribution_pct'])],
            ['Collection Rank — Company', fn ($p) => F::rank($p['collection_rank'], $p['collection_rank_total'])],
            ['Payment Count', fn ($p) => F::int($p['payment_count'])],
            ['Average Payment Value', fn ($p) => F::money($p['average_payment_value'])],
            ['Largest Payment Value', fn ($p) => F::money($p['largest_payment_day'])],
            ['Average Payment Interval', fn ($p) => $p['average_payment_interval_days'] === null ? F::DASH : F::dec2($p['average_payment_interval_days']).' days'],
        ], 0, $ent ? 7 : 11),
    ])

    @include('customers.c360.target-table', [
        'icon' => 'target', 'title' => 'Collection Target vs Actual — '.($first['report_year'] ?? ''), 'rows' => $collectionTarget,
        'totalLabel' => 'Total', 'notes' => $targetNotes, 'empty' => 'No collection target data.',
        'defs' => [
            ['Collection TGT', fn ($r) => F::money($r['collection_tgt'])],
            ['Actual Collection', fn ($r) => F::money($r['actual_collection'])],
            ['Ach %', fn ($r) => F::pct2($r['ach_pct'])],
            ['Balance', fn ($r) => F::money($r['balance'])],
        ],
    ])

    @include('customers.c360.line-charts', [
        'months' => $collectionMonthly, 'cols' => $ent ? 'c3col' : 'c2col', 'empty' => 'No chart data.',
        'charts' => array_slice([
            ['id' => 'col_net', 'title' => 'Collection — Last 12 Months', 'icon' => 'wallet', 'key' => 'net_collection', 'kind' => 'money', 'zero' => true,
                'tip' => fn ($r) => 'SAR '.F::money($r['net_collection']), 'lead' => null],
            ['id' => 'col_ratio', 'title' => 'Collection Ratio vs Sales — Last 12 Months', 'icon' => 'percent', 'key' => 'collection_ratio_pct', 'kind' => 'pct', 'zero' => false,
                'tip' => fn ($r) => F::pct2($r['collection_ratio_pct']), 'lead' => 'Collection ÷ Net Sales of the same month.'],
            ['id' => 'col_avg', 'title' => 'Collection Average — Last 12 Months', 'icon' => 'line-chart', 'key' => 'collection_average_daily', 'kind' => 'money', 'zero' => true,
                'tip' => fn ($r) => $r['collection_average_daily'] === null ? F::DASH : 'SAR '.F::money($r['collection_average_daily']),
                'lead' => 'Daily Collection Average within each month (nonzero Collection days only).'],
            ['id' => 'col_avgpay', 'title' => 'Average Payment Value — Last 12 Months', 'icon' => 'receipt', 'key' => 'average_payment_value', 'kind' => 'money', 'zero' => false,
                'tip' => fn ($r) => $r['average_payment_value'] === null ? F::DASH : 'SAR '.F::money($r['average_payment_value']),
                'lead' => 'Actual payments ÷ payment count of the same month (refunds excluded).'],
        ], 0, $ent ? 3 : 4),
    ])
</div>
