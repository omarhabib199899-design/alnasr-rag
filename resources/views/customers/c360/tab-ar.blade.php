{{-- Accounts Receivable — their ArOverviewSection (9 KPI snapshot + invoice drawer) + ArAgingSection (table + bar chart). --}}
@php
    use App\Support\C360Format as F;
    $o = $arOverview;
    $kpis = $o ? [
        ['Outstanding AR', F::sar($o['outstanding_ar']), null],
        ['Outstanding Invoices Count', F::int($o['outstanding_invoices_count']), 'outstanding'],
        ['Outstanding Contribution % (Company)', F::pct2($o['outstanding_contribution_pct']), null],
        ['Overdue Amount', F::sar($o['overdue_amount']), null],
        ['Overdue Invoices Count', F::int($o['overdue_invoices_count']), 'overdue'],
        ['Overdue Ratio %', F::pct2($o['overdue_ratio_pct']), null],
        ['Overdue Contribution % (Company)', F::pct2($o['overdue_contribution_pct']), null],
        ['Due This Month', F::sar($o['due_this_month']), null],
        ['Due This Month Ratio %', F::pct2($o['due_this_month_ratio_pct']), null],
    ] : [];
    $bucketLabels = ['0_30' => '0–30 Days', '31_60' => '31–60 Days', '61_90' => '61–90 Days', '91_120' => '91–120 Days',
        '121_150' => '121–150 Days', '151_180' => '151–180 Days', 'over_180' => 'Over 180 Days'];
    $aging = collect($arAging)->sortBy('bucket_order')->values();
    $agingTotal = $aging[0]['total_amount'] ?? 0;
    $future = $aging[0]['future_dated_count'] ?? 0;
@endphp
<div class="c3-stack">
    @if (! $o)
        <p class="c3-muted">No accounts receivable data.</p>
    @else
        <section class="c3-card">
            <header class="c3-card-h">
                <i data-lucide="receipt" class="ico-18"></i><h2>AR Overview</h2>
                <span class="meta">Snapshot as of <bdi dir="ltr">{{ F::dmy($o['as_of_date']) }}</bdi></span>
            </header>
            <div class="c3-card-b" style="padding-top: 16px; padding-bottom: 16px">
                <div class="c3-kgrid k3">
                    @foreach ($kpis as [$label, $value, $details])
                        <div class="c3-kpi">
                            <div class="l">
                                <span>{{ $label }}</span>
                                @if ($details)
                                    <button type="button" class="c3-iconbtn" title="Show invoices — {{ $label }}" aria-label="Show invoices — {{ $label }}"
                                            @click="openDrawer('{{ $details }}', @js($label))"><i data-lucide="list-checks" class="ico"></i></button>
                                @endif
                            </div>
                            <div class="v"><bdi dir="ltr">{{ $value }}</bdi></div>
                        </div>
                    @endforeach
                </div>
                <div style="margin-top: 12px">
                    <p class="c3-note" style="margin: 2px 0 0">Outstanding AR = net statement balance (sum of all statement amounts, signed). Outstanding Invoices Count = invoices and customer opening balance documents with a positive balance.</p>
                    <p class="c3-note" style="margin: 2px 0 0">Overdue = positive balances of invoices and opening balances with a due date before today (invoices due today are not overdue). Ratios use Outstanding AR as the denominator and may exceed 100%.</p>
                    <p class="c3-note" style="margin: 2px 0 0">Due This Month = positive balances of invoices and opening balances due on or before <bdi dir="ltr">{{ F::dmy($o['month_end']) }}</bdi>, including amounts already overdue.</p>
                </div>
            </div>
        </section>
    @endif

    @if ($aging->isNotEmpty())
        @php
            $cfg = [
                'type' => 'aging',
                'labels' => $aging->map(fn ($r) => $bucketLabels[$r['bucket_key']] ?? 'Over 180 Days')->all(),
                'values' => $aging->map(fn ($r) => (float) $r['amount'])->all(),
                'tipLines' => $aging->map(fn ($r) => ['SAR '.F::money($r['amount']), F::pct2($r['pct_of_total'])])->all(),
            ];
        @endphp
        <section class="c3-card">
            <header class="c3-card-h"><i data-lucide="hourglass" class="ico-18"></i><h2>AR Aging Analysis</h2></header>
            <div class="c3-grid2" style="padding: 16px">
                <div class="c3-scroll">
                    <table class="c3-tbl" style="min-width: 420px">
                        <thead><tr><th class="start">Aging Bucket</th><th>Amount (SAR)</th><th>% of Total Aging Balance</th></tr></thead>
                        <tbody>
                            @foreach ($aging as $r)
                                <tr>
                                    <th scope="row">{{ $bucketLabels[$r['bucket_key']] ?? 'Over 180 Days' }}</th>
                                    <td><bdi dir="ltr">{{ F::money($r['amount']) }}</bdi></td>
                                    <td><bdi dir="ltr">{{ F::pct2($r['pct_of_total']) }}</bdi></td>
                                </tr>
                            @endforeach
                            <tr class="nohover">
                                <th scope="row" class="tot">Total</th>
                                <td class="tot"><bdi dir="ltr">{{ F::money($agingTotal) }}</bdi></td>
                                <td class="tot"><bdi dir="ltr">{{ $agingTotal > 0 ? '100.00%' : F::DASH }}</bdi></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div>
                    <div style="margin-bottom: 4px; font-size: 13px; font-weight: 600; color: var(--c360-heading)">Aging Distribution</div>
                    <div class="c3-chart-box" style="height: 300px" dir="ltr"><canvas data-c3chart="ar_aging"></canvas></div>
                </div>
            </div>
            <div style="padding: 0 16px 12px">
                <p class="c3-note" style="margin: 2px 0 0">Aging is based on the invoice date (calendar days from the invoice date to today), using the positive outstanding balance of invoices and opening balances. Opening balances carry the 2024-04-01 opening date, so they always fall in Over 180 Days.</p>
                <p class="c3-note" style="margin: 2px 0 0">Total Aging Balance covers positive invoice and opening balances only, so it can differ from Outstanding AR, which also nets credit notes, payments and unapplied amounts.</p>
                @if ($future > 0)
                    <p class="c3-note" style="margin: 2px 0 0">{{ number_format($future) }} records dated after today are not included in any bucket.</p>
                @endif
            </div>
        </section>
        <script>C360.register('ar_aging', @json($cfg));</script>
    @elseif ($o)
        <p class="c3-muted">No accounts receivable data.</p>
    @endif
</div>
