{{-- Row of "Last 12 Months" line charts (their CustomerSalesCharts / CreditNoteCharts / ProfitabilityCharts /
     CollectionCharts). $months: monthly rows (month_start, is_current_month, as_of_date, ...); $cols: c3col | c2col;
     $charts: list of [id, title, icon, key, kind (money|int|pct), zero (bool), tip (fn row => string), lead (?string)]. --}}
@php
    use App\Support\C360Format as F;
    $asOf = $months[0]['as_of_date'] ?? null;
    $currentNote = '* Current month — actual data through '.e(F::dmy($asOf)).' (incomplete month, not extrapolated).';
@endphp
@if (empty($months))
    <p class="c3-muted">{{ $empty ?? 'No chart data.' }}</p>
@else
    <div class="c3-charts {{ $cols }}">
        @foreach ($charts as $c)
            @php
                $note = ($c['lead'] ? e($c['lead']).' ' : '').$currentNote;
                $cfg = [
                    'type' => 'line', 'kind' => $c['kind'], 'zero' => $c['zero'],
                    'labels' => array_map(fn ($r) => F::monthShort($r['month_start'], $r['is_current_month']), $months),
                    'values' => array_map(fn ($r) => $r[$c['key']] === null ? null : (float) $r[$c['key']], $months),
                    'current' => array_map(fn ($r) => (bool) $r['is_current_month'], $months),
                    'tipTitles' => array_map(fn ($r) => $r['is_current_month']
                        ? F::monthFull($r['month_start']).' * (through '.F::dmy($asOf).')' : F::monthFull($r['month_start']), $months),
                    'tipValues' => array_map($c['tip'], $months),
                ];
            @endphp
            <section class="c3-card" x-ref="card_{{ $c['id'] }}">
                <header class="c3-card-h">
                    <i data-lucide="{{ $c['icon'] }}" class="ico-18"></i>
                    <h2>{{ $c['title'] }}</h2>
                    <button type="button" class="c3-expand" title="Expand" aria-label="Expand {{ $c['title'] }}"
                            @click="openChart({ id: '{{ $c['id'] }}', title: @js($c['title']), note: @js($note) }, $event)">
                        <i data-lucide="maximize-2" class="ico"></i>
                    </button>
                </header>
                <div class="c3-chart-body">
                    <div class="c3-chart-box" dir="ltr"><canvas data-c3chart="{{ $c['id'] }}"></canvas></div>
                    <p class="c3-note">{!! $note !!}</p>
                </div>
            </section>
            <script>C360.register(@js($c['id']), @json($cfg));</script>
        @endforeach
    </div>
@endif
