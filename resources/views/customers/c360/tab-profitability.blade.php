{{-- Profitability — their ProfitabilityKpis + ProfitabilityCharts. --}}
@php
    use App\Support\C360Format as F;
    $excluded = collect($profitabilityKpis)->firstWhere('period_key', 'lifetime')['gp_pct_excluded'] ?? 0;
@endphp
<div class="c3-stack">
    @include('customers.c360.matrix', [
        'icon' => 'trending-up', 'title' => 'Profitability Performance', 'data' => $profitabilityKpis, 'empty' => 'No profitability data available.',
        'notes' => [
            'Invoices only — credit notes, refunds, collections and journals are excluded. Gross Profit and GP% are the stored invoice values. Gross Margin % = Gross Profit ÷ invoice sales excluding VAT. GP Contribution % = share of company Gross Profit. GP Rank compares against the same entity type (all customers on Customer 360) with Gross Profit above zero.',
            'Average GP% per Invoice excludes invoices with zero sales or a stored GP% below −100% ('.number_format($excluded).' invoices in Lifetime).',
        ],
        'rows' => [
            ['Gross Profit', fn ($p) => F::money($p['gross_profit'])],
            ['Gross Margin %', fn ($p) => F::pct2($p['gross_margin_pct'])],
            ['GP Contribution %', fn ($p) => F::pct2($p['gp_contribution_pct'])],
            ['GP Rank', fn ($p) => F::rank($p['gp_rank'], $p['gp_rank_total'])],
            ['Average GP% per Invoice', fn ($p) => F::pct2($p['avg_gp_pct_per_invoice'])],
        ],
    ])

    @include('customers.c360.line-charts', [
        'months' => $profitabilityMonthly, 'cols' => 'c3col', 'empty' => 'No monthly profitability data available.',
        'charts' => [
            ['id' => 'pr_gp', 'title' => 'Gross Profit — Last 12 Months', 'icon' => 'coins', 'key' => 'gross_profit', 'kind' => 'money', 'zero' => false,
                'tip' => fn ($r) => 'SAR '.F::money($r['gross_profit']), 'lead' => null],
            ['id' => 'pr_margin', 'title' => 'Gross Margin % — Last 12 Months', 'icon' => 'percent', 'key' => 'gross_margin_pct', 'kind' => 'pct', 'zero' => false,
                'tip' => fn ($r) => F::pct2($r['gross_margin_pct']), 'lead' => 'Gross Profit ÷ invoice sales excluding VAT.'],
            ['id' => 'pr_contrib', 'title' => 'GP Contribution % — Last 12 Months', 'icon' => 'pie-chart', 'key' => 'gp_contribution_pct', 'kind' => 'pct', 'zero' => false,
                'tip' => fn ($r) => F::pct2($r['gp_contribution_pct']), 'lead' => 'Share of company Gross Profit in the same month.'],
        ],
    ])
</div>
