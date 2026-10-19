{{-- Months + Total/Annual target table (their CustomerSalesTargetVsActual / CollectionTargetVsActual /
     TargetAchievementTables). $rows: month rows (month_no set) + one total row (month_no null); $defs: list of
     [label, fn(row) => string]; $icon, $title, $totalLabel, $notes (escaped HTML), $empty. --}}
@php
    use App\Support\C360Format as F;
    $monthRows = collect($rows)->filter(fn ($r) => $r['month_no'] !== null)->sortBy('month_no')->values();
    $total = collect($rows)->first(fn ($r) => $r['month_no'] === null);
@endphp
<section class="c3-card">
    <header class="c3-card-h"><i data-lucide="{{ $icon }}" class="ico-18"></i><h2>{{ $title }}</h2></header>
    <div class="c3-card-b">
        @if ($monthRows->isEmpty())
            <p class="c3-muted">{{ $empty }}</p>
        @else
            <div class="c3-scroll">
                <table class="c3-tbl" style="min-width: 1100px">
                    <thead>
                        <tr>
                            <th class="start" style="width: {{ $kpiWidth ?? '9rem' }}">KPI</th>
                            @foreach ($monthRows as $r)
                                <th @class(['cur' => $r['is_current_month']])>{{ F::MONTHS_SHORT[$r['month_no'] - 1] }}{{ $r['is_current_month'] ? '*' : '' }}</th>
                            @endforeach
                            <th>{{ $totalLabel }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($defs as [$label, $render])
                            <tr class="nohover">
                                <th scope="row">{{ $label }}</th>
                                @foreach ($monthRows as $r)
                                    <td @class(['cur' => $r['is_current_month']])><bdi dir="ltr">{{ $render($r) }}</bdi></td>
                                @endforeach
                                <td class="tot"><bdi dir="ltr">{{ $total ? $render($total) : F::DASH }}</bdi></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div style="margin-top: 8px">
                @foreach ($notes as $note)
                    <p class="c3-note" style="margin: 2px 0 0">{!! $note !!}</p>
                @endforeach
            </div>
        @endif
    </div>
</section>
