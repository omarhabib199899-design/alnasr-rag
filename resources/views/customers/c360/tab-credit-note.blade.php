{{-- Credit Note — their CreditNoteKpis + CreditNoteCharts. --}}
@php use App\Support\C360Format as F; @endphp
<div class="c3-stack">
    @include('customers.c360.matrix', [
        'icon' => 'file-minus', 'title' => 'Credit Note Performance', 'data' => $creditNoteKpis, 'empty' => 'No credit note data.',
        'notes' => ['Return Rate % = Return Value ÷ Gross Sales of the same period. Return Average = Return Value ÷ Credit Notes Count.'],
        'rows' => [
            ['Return Value', fn ($p) => F::money($p['return_value'])],
            ['Credit Notes Count', fn ($p) => F::int($p['credit_notes_count'])],
            ['Return Average', fn ($p) => F::money($p['return_average'])],
            ['Return Rate %', fn ($p) => F::pct2($p['return_rate_pct'])],
        ],
    ])

    @include('customers.c360.line-charts', [
        'months' => $creditNoteMonthly, 'cols' => 'c3col', 'empty' => 'No chart data.',
        'charts' => [
            ['id' => 'cn_value', 'title' => 'Return Value — Last 12 Months', 'icon' => 'undo-2', 'key' => 'return_value', 'kind' => 'money', 'zero' => false,
                'tip' => fn ($r) => 'SAR '.F::money($r['return_value']), 'lead' => null],
            ['id' => 'cn_count', 'title' => 'Credit Notes Count — Last 12 Months', 'icon' => 'hash', 'key' => 'credit_notes_count', 'kind' => 'int', 'zero' => false,
                'tip' => fn ($r) => F::int($r['credit_notes_count']).' credit notes', 'lead' => null],
            ['id' => 'cn_rate', 'title' => 'Return Rate % — Last 12 Months', 'icon' => 'percent', 'key' => 'return_rate_pct', 'kind' => 'pct', 'zero' => false,
                'tip' => fn ($r) => F::pct2($r['return_rate_pct']), 'lead' => 'Return Value ÷ Gross Sales of the same month.'],
        ],
    ])
</div>
