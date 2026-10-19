{{-- Sales & Collection Target & Achievement (their TargetAchievementTables, portfolio_target_achievement; Annual = month_no null).
     Shared by the Customer 360 and Entity 360 Overview tabs. $targetAchievement. --}}
@php use App\Support\C360Format as F; @endphp
@foreach (['sales' => ['target', 'Sales'], 'collection' => ['wallet', 'Collection']] as $kind => [$icon, $label])
    @php
        $rows = collect($targetAchievement)->where('kind', $kind)->values()->all();
        $first = $rows[0] ?? null;
        $months = collect($rows)->filter(fn ($r) => $r['month_no'] !== null);
        $annual = collect($rows)->first(fn ($r) => $r['month_no'] === null);
        $portfolio = $first['portfolio_customers'] ?? 0;
        $monthlyCov = $months->max('target_customers') ?? 0;
        $annualCov = $annual['target_customers'] ?? 0;
        $notes = [
            '* Current month — actual data through '.e(F::dmy($first['as_of_date'] ?? null)).' (incomplete month, not extrapolated). Future months show —.',
            $kind === 'sales'
                ? 'Annual target = Annual Sales TGT field (not the sum of monthly targets). Annual achievement = YTD Net Sales.'
                : 'Annual target = Annual Collection TGT field (not the sum of monthly targets). Annual achievement = YTD collections.',
        ];
        if ($monthlyCov < $portfolio || $annualCov < $portfolio) {
            $notes[] = 'Target coverage: monthly targets recorded for up to '.number_format($monthlyCov).' of '.number_format($portfolio).' customers; annual target recorded for '
                .number_format($annualCov).' of '.number_format($portfolio).' customers. Missing targets are not counted as zero.';
        }
    @endphp
    @include('customers.c360.target-table', [
        'icon' => $icon, 'title' => "{$label} Target & Achievement — ".($first['report_year'] ?? ''),
        'rows' => $rows, 'totalLabel' => 'Annual', 'kpiWidth' => '11rem', 'notes' => $notes, 'empty' => 'No target data.',
        'defs' => [
            ["{$label} Target", fn ($r) => F::money($r['target'])],
            ["{$label} Achievement", fn ($r) => F::money($r['achievement'])],
            ['Achievement %', fn ($r) => F::pct2($r['ach_pct'])],
            ['Balance', fn ($r) => F::money($r['balance'])],
        ],
    ])
@endforeach
