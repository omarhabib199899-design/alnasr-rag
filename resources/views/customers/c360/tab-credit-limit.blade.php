{{-- Credit Limit — their CreditLimitSection: 8 KPI cards (exposure snapshot, DSO 12M/6M/3M, Recommended = Coming Soon). --}}
@php
    use App\Support\C360Format as F;
    $o = $creditLimit;
    $statusLabel = ['not_available' => 'Not Available', 'no_credit_limit' => 'No Credit Limit', 'credit_balance' => 'Credit Balance',
        'low' => 'Low Utilisation', 'healthy' => 'Healthy Utilisation', 'high' => 'High Utilisation', 'over_limit' => 'Over Limit'];
    $statusStyle = ['not_available' => 'neutral', 'no_credit_limit' => 'neutral', 'credit_balance' => 'info', 'low' => 'info',
        'healthy' => 'healthy', 'high' => 'high', 'over_limit' => 'over'];
    $dso = collect($creditDso)->keyBy('period_key');
    $cutoff = $creditDso[0]['end_date'] ?? null;
    $invalid = $o ? $o['limit_missing_count'] + $o['limit_negative_count'] : 0;
@endphp
@if (! $o)
    <p class="c3-muted">No credit limit data.</p>
@else
<section class="c3-card" style="box-shadow: 0 1px 2px rgba(0,0,0,.05)">
    <header class="c3-card-h"><i data-lucide="badge-check" class="ico-18"></i><h2>Credit Limit</h2></header>
    <div class="c3-card-b" style="padding-top: 16px; padding-bottom: 16px">
        <div class="c3-kgrid k4">
            <div class="c3-kpi"><div class="l">Credit Limit</div><div class="v"><bdi dir="ltr">{{ F::sar($o['credit_limit']) }}</bdi></div></div>
            <div class="c3-kpi"><div class="l">Used Credit</div><div class="v"><bdi dir="ltr">{{ F::sar($o['used_credit']) }}</bdi></div></div>
            <div class="c3-kpi"><div class="l">Credit Utilisation %</div><div class="v"><bdi dir="ltr">{{ F::pct2($o['utilisation_pct']) }}</bdi></div></div>
            <div class="c3-kpi"><div class="l">Credit Status</div>
                <div class="v"><span class="c3-status {{ $statusStyle[$o['status_key']] }}">{{ $statusLabel[$o['status_key']] }}</span></div></div>
            @foreach (['12m' => 'DSO (12M)', '6m' => 'DSO (6M)', '3m' => 'DSO (3M)'] as $k => $label)
                @php $p = $dso[$k] ?? null; @endphp
                <div class="c3-kpi">
                    <div class="l">{{ $label }}</div>
                    <div class="v"><bdi dir="ltr">{{ ($p['dso_days'] ?? null) === null ? F::DASH : number_format($p['dso_days'], 1).' days' }}</bdi></div>
                    @if ($p && $p['start_date'] && $p['end_date'])
                        <div class="s"><bdi dir="ltr">{{ F::dmy($p['start_date']) }}</bdi> → <bdi dir="ltr">{{ F::dmy($p['end_date']) }}</bdi> (<bdi dir="ltr">{{ $p['days'] }}</bdi> days)</div>
                    @endif
                </div>
            @endforeach
            <div class="c3-kpi"><div class="l">Recommended Credit Limit</div>
                <div class="v"><span style="font-size: 16px; font-weight: 600; color: var(--c360-label)">Coming Soon</span></div></div>
        </div>
        <div style="margin-top: 12px">
            <p class="c3-note" style="margin: 2px 0 0">Credit Limit = customer credit limit in Zoho (sum of the portfolio's customers). Used Credit = Outstanding AR (net statement balance). Credit Utilisation % = Used Credit ÷ Credit Limit.</p>
            <p class="c3-note" style="margin: 2px 0 0">Status: below 50% Low · 50% to below 80% Healthy · 80% to 100% High · above 100% Over Limit. Utilisation describes credit usage only, not a credit-risk rating.</p>
            <p class="c3-note" style="margin: 2px 0 0">DSO = ((Opening AR + Closing AR) ÷ 2) ÷ Net Sales × actual days, rolling windows ending <bdi dir="ltr">{{ F::dmy($cutoff) }}</bdi> (the last date completely covered by the data sync). Net Sales is used for credit sales because Zoho does not separate cash sales; — when Net Sales is zero or negative. Portfolios use current customer assignments.</p>
            @if ($invalid > 0)
                <p class="c3-note" style="margin: 2px 0 0">{{ $invalid }} customers have a missing or negative credit limit.</p>
            @endif
        </div>
    </div>
</section>
@endif
