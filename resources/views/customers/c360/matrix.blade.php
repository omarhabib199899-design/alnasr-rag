{{-- KPI × 7 periods table (their CustomerSalesKpis / CreditNoteKpis / ProfitabilityKpis / CollectionKpis layout).
     $icon, $title, $data (period rows), $rows: list of [label, fn(array $periodRow): string], $notes: escaped HTML lines, $empty. --}}
@php
    use App\Support\C360Format as F;
    $byKey = collect($data)->keyBy('period_key');
@endphp
<section class="c3-card">
    <header class="c3-card-h"><i data-lucide="{{ $icon }}" class="ico-18"></i><h2>{{ $title }}</h2></header>
    <div class="c3-card-b">
        @if ($byKey->isEmpty())
            <p class="c3-muted">{{ $empty }}</p>
        @else
            <div class="c3-scroll">
                <table class="c3-tbl" style="min-width: 960px">
                    <thead>
                        <tr>
                            <th class="start" style="width: 14rem">KPI</th>
                            @foreach (F::PERIODS as $label)
                                <th>{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as [$label, $render])
                            <tr>
                                <th scope="row">{{ $label }}</th>
                                @foreach (F::PERIODS as $key => $_)
                                    <td><bdi dir="ltr">{{ $byKey->has($key) ? $render($byKey[$key]) : F::DASH }}</bdi></td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @foreach ($notes ?? [] as $i => $note)
                <p class="c3-note" @if ($i > 0) style="margin-top: 4px" @endif>{!! $note !!}</p>
            @endforeach
        @endif
    </div>
</section>
