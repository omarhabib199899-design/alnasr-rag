{{-- Item Performance — their ItemPerformanceSection: A) owner × 7 periods matrix with owner tabs, B) 8 rankings
     (YTD / MTD × 4), C) 6 contribution charts with a shared YTD / MTD selector. --}}
@php
    use App\Support\C360Format as F;
    $owners = collect($itemOwnerKpis)->groupBy('owner_order')->sortKeys();
    $rankings = collect($itemRankings);
    $contrib = collect($itemContribution);
    $palette = collect([1, 2, 3, 4, 5, 1, 2, 3, 4, 5])->map(fn ($n, $i) => ["--chart-{$n}", $i < 5 ? 1 : 0.5])->all();
    $ownerRows = [
        ['Net Sales', fn ($r) => F::money($r['net_sales'])],
        ['Avg Sales', fn ($r) => F::money($r['avg_sales'])],
        ['Sales Contribution %', fn ($r) => F::pct2($r['sales_contribution_pct'])],
        ['SKU Count', fn ($r) => F::int($r['sku_count'])],
        ['Profit %', fn ($r) => F::pct2($r['profit_pct'])],
        ['Profit Contribution %', fn ($r) => F::pct2($r['profit_contribution_pct'])],
    ];
    $rankDefs = [
        ['items_sales', 'Top 20 Items by Sales', true, 'Net Sales'],
        ['items_gp', 'Top 20 Items by Profit Value', true, 'Gross Profit'],
        ['vendors_sales', 'Top 10 Vendors by Sales', false, 'Net Sales'],
        ['mc_sales', 'Top 20 Marketing Companies by Sales', false, 'Net Sales'],
    ];
    $chartDefs = [
        'sales' => ['Sales Contribution', [['vendor', 'Vendor Sales Contribution'], ['marketing_company', 'Marketing Company Sales Contribution'], ['owner', 'Item Owner Sales Contribution']]],
        'profit' => ['Profitability Contribution', [['vendor', 'Vendor Profitability Contribution'], ['marketing_company', 'Marketing Company Profitability Contribution'], ['owner', 'Item Owner Profitability Contribution']]],
    ];
@endphp
<div class="c3-stack">
    {{-- A) Item Owner Performance --}}
    @if ($owners->isEmpty())
        <p class="c3-muted">No item sales activity for this scope.</p>
    @else
        <section class="c3-card" style="box-shadow: 0 1px 2px rgba(0,0,0,.05)" x-data="{ owner: {{ $owners->keys()->first() }} }">
            <header class="c3-card-h"><i data-lucide="package" class="ico-18"></i><h2>Item Owner Performance</h2></header>
            <div class="c3-subtabs" role="tablist" aria-label="Item owners">
                @foreach ($owners as $ord => $rows)
                    <button type="button" role="tab" class="c3-subtab" :class="owner === {{ $ord }} && 'on'" @click="owner = {{ $ord }}">
                        <span dir="auto">{{ $rows[0]['owner'] ?? 'Unassigned' }}</span>
                    </button>
                @endforeach
            </div>
            <div class="c3-card-b">
                @foreach ($owners as $ord => $rows)
                    @php $byP = $rows->keyBy('period_key'); @endphp
                    <div class="c3-scroll" x-show="owner === {{ $ord }}" @if (! $loop->first) x-cloak @endif>
                        <table class="c3-tbl" style="min-width: 960px">
                            <thead><tr><th class="start" style="width: 14rem">KPI</th>@foreach (F::PERIODS as $l)<th>{{ $l }}</th>@endforeach</tr></thead>
                            <tbody>
                                @foreach ($ownerRows as [$label, $render])
                                    <tr><th scope="row">{{ $label }}</th>
                                        @foreach (F::PERIODS as $k => $_)<td><bdi dir="ltr">{{ $byP->has($k) ? $render($byP[$k]) : F::DASH }}</bdi></td>@endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
                <p class="c3-note">Item-level data (Sales Item Performance, excluding VAT): Net Sales = invoice item lines minus returned item lines. Financial credit notes without item lines are not included, so totals can differ from the Sales tab. Profit % and Profit Contribution % use invoices only (stored Item Gross Profit). SKU Count = items with net quantity above zero. Owner = current Item Owner in Zoho Items.</p>
            </div>
        </section>
    @endif

    {{-- B) Overall Item Rankings --}}
    @foreach (['ytd' => 'Ranking — Year to Date (YTD)', 'mtd' => 'Ranking — Month to Date (MTD)'] as $pk => $title)
        @php $mine = $rankings->where('period_key', $pk); $range = $mine->first(); @endphp
        <section class="c3-card" style="box-shadow: 0 1px 2px rgba(0,0,0,.05)">
            <header class="c3-card-h">
                <i data-lucide="trophy" class="ico-18"></i><h2>{{ $title }}</h2>
                @if ($range)
                    <span class="meta"><bdi dir="ltr">{{ F::dmy($range['period_start']) }}</bdi> → <bdi dir="ltr">{{ F::dmy($range['period_end']) }}</bdi></span>
                @endif
            </header>
            <div class="c3-xl2">
                @foreach ($rankDefs as [$rk, $rtitle, $isItem, $valueLabel])
                    @php $rows = $mine->where('ranking_key', $rk)->sortBy('row_position'); @endphp
                    <div class="c3-rank">
                        <div class="c3-rank-h">{{ $rtitle }}</div>
                        @if ($rows->isEmpty())
                            <p style="padding: 16px 12px; margin: 0; font-size: 12.5px; color: var(--c360-label)">No data for this period.</p>
                        @else
                            <div class="c3-scroll">
                                <table class="c3-list">
                                    <thead><tr>
                                        <th style="width: 2.5rem; text-align: end">#</th>
                                        <th style="text-align: start">{{ $isItem ? 'Item Name' : 'Name' }}</th>
                                        @if ($isItem)<th style="text-align: start">Item ID</th>@endif
                                        <th style="text-align: end">{{ $valueLabel }}</th>
                                    </tr></thead>
                                    <tbody>
                                        @foreach ($rows as $r)
                                            <tr>
                                                <td class="tnum" style="text-align: end; color: var(--c360-label)">{{ number_format($r['rank_no']) }}</td>
                                                <td style="white-space: normal; color: var(--c360-heading)"><span dir="auto">{{ $r['name'] ?? 'Unassigned' }}</span></td>
                                                @if ($isItem)<td class="tnum" style="color: var(--c360-label)"><bdi dir="ltr">{{ $r['item_id'] ?? F::DASH }}</bdi></td>@endif
                                                <td class="tnum" style="text-align: end; font-weight: 600; color: var(--c360-heading)"><bdi dir="ltr">{{ F::money($r['value']) }}</bdi></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
            <p class="c3-note" style="padding: 0 16px 12px; margin: 0">Rankings include all items in this scope and do not depend on the selected owner. Only positive values are ranked; equal values share a rank. Profit Value uses invoices only. Item name, Vendor and Marketing Company are the current values in Zoho Items.</p>
        </section>
    @endforeach

    {{-- C) Contribution Charts --}}
    <section class="c3-card" style="box-shadow: 0 1px 2px rgba(0,0,0,.05)" x-data="{ period: 'ytd' }" x-init="$watch('period', () => $nextTick(() => C360.initIn($el)))">
        <header class="c3-card-h">
            <i data-lucide="pie-chart" class="ico-18"></i><h2>Contribution Charts</h2>
            <div class="c3-seg" role="radiogroup" aria-label="Chart period">
                <button type="button" role="radio" :class="period === 'ytd' && 'on'" :aria-checked="period === 'ytd'" @click="period = 'ytd'">YTD</button>
                <button type="button" role="radio" :class="period === 'mtd' && 'on'" :aria-checked="period === 'mtd'" @click="period = 'mtd'">MTD</button>
            </div>
        </header>
        <div class="c3-card-b" style="padding-top: 16px; padding-bottom: 16px">
            <div class="c3-stack">
                @foreach ($chartDefs as $metric => [$groupTitle, $defs])
                    <div style="display: flex; flex-direction: column; gap: 8px">
                        <h3 style="margin: 0; font-size: 13px; font-weight: 600; color: var(--c360-heading)">{{ $groupTitle }}</h3>
                        <div class="c3-charts c3col">
                            @foreach ($defs as [$dim, $ctitle])
                                <section class="c3-card" style="box-shadow: 0 1px 2px rgba(0,0,0,.05)">
                                    <header class="c3-card-h"><i data-lucide="pie-chart" class="ico-18"></i><h2>{{ $ctitle }}</h2></header>
                                    <div style="padding: 12px">
                                        @foreach (['ytd', 'mtd'] as $pk)
                                            @php
                                                $rows = $contrib->where('period_key', $pk)->where('dimension', $dim)->where('metric', $metric)->sortBy('slice_order')->values();
                                                $first = $rows->first();
                                                $valid = $first && $first['total_positive'] && $rows->every(fn ($r) => $r['pct'] !== null);
                                                $donut = $valid && $rows->every(fn ($r) => $r['pct'] >= 0);
                                                $slices = $rows->map(fn ($r, $i) => [
                                                    'label' => $r['is_other'] ? "Others ({$r['other_count']})" : ($r['name'] ?? 'Unassigned'),
                                                    'pct' => (float) ($r['pct'] ?? 0),
                                                    'color' => $r['is_other'] ? ['--c360-muted', 0.45] : $palette[$i % 10],
                                                ]);
                                                $othersNeg = $rows->firstWhere('is_other', true)['other_negative_count'] ?? 0;
                                                $id = "contrib_{$pk}_{$dim}_{$metric}";
                                            @endphp
                                            <div x-show="period === '{{ $pk }}'" @if ($pk === 'mtd') x-cloak @endif>
                                                @if (! $first)
                                                    <p style="padding: 24px 4px; margin: 0; font-size: 12.5px; color: var(--c360-label)">No data for this period.</p>
                                                @elseif (! $valid)
                                                    <p style="padding: 24px 4px; margin: 0; font-size: 12.5px; color: var(--c360-label)">Contribution is not shown: the total for this period is zero or negative.</p>
                                                @else
                                                    <div style="display: flex; flex-direction: column; gap: 8px">
                                                        <div class="c3-chart-box" dir="ltr" style="height: {{ $donut ? 220 : max(220, $slices->count() * 28 + 40) }}px"><canvas data-c3chart="{{ $id }}"></canvas></div>
                                                        @if ($donut)
                                                            <ul class="c3-legend">
                                                                @foreach ($slices as $s)
                                                                    <li><span class="sw" style="background: var({{ $s['color'][0] }}); opacity: {{ $s['color'][1] }}"></span>
                                                                        <span class="nm" dir="auto" title="{{ $s['label'] }}">{{ $s['label'] }}</span>
                                                                        <span class="pc"><bdi dir="ltr">{{ F::pct2($s['pct']) }}</bdi></span></li>
                                                                @endforeach
                                                            </ul>
                                                        @endif
                                                    </div>
                                                    <div style="margin-top: 8px; padding: 0 4px">
                                                        @if (! $donut)<p class="c3-note" style="margin: 2px 0 0">Shown as bars because at least one contribution is negative.</p>@endif
                                                        @if ($othersNeg > 0)<p class="c3-note" style="margin: 2px 0 0">Others includes {{ number_format($othersNeg) }} entities with a negative contribution.</p>@endif
                                                    </div>
                                                    @php $cfg = ['type' => 'contribution', 'donut' => $donut, 'labels' => $slices->pluck('label'), 'values' => $slices->pluck('pct'), 'colors' => $slices->pluck('color')]; @endphp
                                                    <script>C360.register(@js($id), @json($cfg));</script>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </section>
                            @endforeach
                        </div>
                    </div>
                @endforeach
                <p class="c3-note" style="margin: 0">Percent of total item Net Sales (sales) or of total invoice Gross Profit (profitability) in this scope and period. The 10 largest entities are shown individually; the rest are combined in Others. Independent of the selected owner.</p>
            </div>
        </div>
    </section>
</div>
