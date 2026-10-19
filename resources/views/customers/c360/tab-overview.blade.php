{{-- Overview — their CustomerOverview.tsx: 6 field cards (2 columns) + Sales & Collection Target & Achievement. --}}
@php
    use App\Support\C360Format as F;
    $d = $overview ?? [];
    $txt = fn ($v) => ($v === null || trim((string) $v) === '') ? F::DASH : trim((string) $v);
    $raw = fn ($v) => ($v === null || trim((string) $v) === '') ? F::DASH : (string) $v;
    $date = function ($v) use ($txt) {
        if ($v === null) return F::DASH;
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $v, $m) ? "{$m[3]}-{$m[2]}-{$m[1]}" : $txt($v);
    };
    $money = fn ($v) => ($v === null || $v === '') ? F::DASH : F::money((float) $v);
    $relation = F::DASH;
    if (($d['relation_years'] ?? null) !== null && ($d['relation_months'] ?? null) !== null) {
        $y = (int) $d['relation_years'];
        $m = (int) $d['relation_months'];
        $relation = $y.' '.($y === 1 ? 'Year' : 'Years').', '.$m.' '.($m === 1 ? 'Month' : 'Months');
    }
    $status = $txt($d['status'] ?? null);
    $branches = collect(range(1, 10))->map(fn ($n) => ['n' => $n, 'loc' => $txt($d["location_branch_{$n}"] ?? null), 'city' => $txt($d["branch_{$n}_city"] ?? null)])
        ->filter(fn ($b) => $b['loc'] !== F::DASH || $b['city'] !== F::DASH);
    $field = fn (string $label, $value, bool $mono = false) => '<div class="c3-field"><dt>'.e($label).'</dt><dd'.($mono ? ' class="tnum"' : '').'><span dir="'.($mono ? 'ltr' : 'auto').'">'.$value.'</span></dd></div>';
    $badge = fn (string $text, string $tone) => $text === F::DASH ? F::DASH : '<span class="c3-badge '.$tone.'">'.e($text).'</span>';
@endphp

@if (! $overview)
    <p class="c3-muted">No overview data for this customer.</p>
@else
<div class="c3-stack">
    <div class="c3-grid2">
        <div class="c3-stack">
            <section class="c3-card">
                <header class="c3-card-h"><i data-lucide="bar-chart-3" class="ico-18"></i><h2>Customer Profile</h2></header>
                <div class="c3-card-b"><dl class="c3-fields">
                    {!! $field('Customer Name', e($txt($d['customer_name']))) !!}
                    {!! $field('Status', $badge($status, $status === 'Active' ? 'success' : 'neutral')) !!}
                    {!! $field('Customer Number', e($raw($d['customer_number'])), true) !!}
                    {!! $field('Account Type', e($txt($d['account_type']))) !!}
                    {!! $field('Business Relation Time', e($relation)) !!}
                </dl></div>
            </section>

            <section class="c3-card">
                <header class="c3-card-h"><i data-lucide="tag" class="ico-18"></i><h2>Customer Classifications</h2></header>
                <div class="c3-card-b"><dl class="c3-fields">
                    {!! $field('Sales Classification', e($txt($d['sales_classification']))) !!}
                    {!! $field('Profit Classification', e($txt($d['profitability_classification']))) !!}
                    {!! $field('Collection Classification', e($txt($d['collection_classification']))) !!}
                    {!! $field('Payment Trend', e($txt($d['payment_timing_pattern']))) !!}
                    {!! $field('AR Risk Classification', e($txt($d['final_risk']))) !!}
                    {!! $field('Documents Classification', e($txt($d['documents_classification']))) !!}
                </dl></div>
            </section>

            <section class="c3-card">
                <header class="c3-card-h"><i data-lucide="map-pin" class="ico-18"></i><h2>Locations &amp; Branches</h2></header>
                <div class="c3-card-b">
                    <div class="c3-info3">
                        <div class="c3-info"><div class="l">City</div><div class="v" dir="auto">{{ $txt($d['city']) }}</div></div>
                        <div class="c3-info"><div class="l">WH CITY</div><div class="v" dir="auto">{{ $txt($d['wh_city']) }}</div></div>
                        <div class="c3-info"><div class="l">Delivery time</div><div class="v" dir="auto">{{ $txt($d['delivery_time']) }}</div></div>
                    </div>
                    <div class="c3-branches">
                        <table>
                            <thead><tr><th style="width: 6rem">Branch #</th><th>Location</th><th>City</th></tr></thead>
                            <tbody>
                                @forelse ($branches as $b)
                                    <tr>
                                        <td class="n tnum">{{ $b['n'] }}</td>
                                        <td dir="auto">{{ $b['loc'] }}</td>
                                        <td dir="auto">{{ $b['city'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" style="padding: 28px 12px; text-align: center">
                                        <i data-lucide="building-2" style="width: 28px; height: 28px; color: var(--c360-label); stroke-width: 1.5"></i>
                                        <div style="margin-top: 8px; font-size: 13px; font-weight: 500; color: var(--c360-value)">No branches recorded</div>
                                    </td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>

        <div class="c3-stack">
            <section class="c3-card">
                <header class="c3-card-h"><i data-lucide="credit-card" class="ico-18"></i><h2>Credit &amp; Finance</h2></header>
                <div class="c3-card-b"><dl class="c3-fields">
                    {!! $field('Credit Limit', e($money($d['credit_limit'])), true) !!}
                    {!! $field('Payment Terms', e($d['payments_due'] === null ? F::DASH : (string) (int) $d['payments_due']), true) !!}
                    {!! $field('Attested account', e($txt($d['attested_account']))) !!}
                    {!! $field('Last Reconciliation date', e($date($d['last_reconciliation_date'])), true) !!}
                    {!! $field('RECONCILIATION STATUS', $badge($txt($d['reconciliation_status']), 'info')) !!}
                    {!! $field('Promissory Note', e($money($d['promissory_note'])), true) !!}
                    {!! $field('Promissory Note Issue Date', e($date($d['promissory_note_issue_date'])), true) !!}
                    {!! $field('Finance Dept Last Review Date', e($date($d['finance_dept_last_review_date'])), true) !!}
                    {!! $field('Finance Dept Recommendation', e($txt($d['finance_dept_recommendation']))) !!}
                </dl></div>
            </section>

            <section class="c3-card">
                <header class="c3-card-h"><i data-lucide="file-text" class="ico-18"></i><h2>Legal &amp; Regulatory</h2></header>
                <div class="c3-card-b"><dl class="c3-fields">
                    {!! $field('CR NO', e($raw($d['cr_no'])), true) !!}
                    {!! $field('CR Expiry Date', e($date($d['cr_expiry_date'])), true) !!}
                    {!! $field('CR Status', e($txt($d['cr_status']))) !!}
                    {!! $field('Tax Registration Number', e($raw($d['tax_registration_number'])), true) !!}
                    {!! $field('GLN NO', e($raw($d['gln_no'])), true) !!}
                    {!! $field('MOH SFDA License Expiry Date', e($date($d['moh_sfda_license_expiry_date'])), true) !!}
                </dl></div>
            </section>

            <section class="c3-card">
                <header class="c3-card-h"><i data-lucide="users" class="ico-18"></i><h2>Account Ownership</h2></header>
                <div class="c3-card-b"><dl class="c3-fields">
                    {!! $field('Rep Name', e($txt($d['rep_name']))) !!}
                    {!! $field('SALES OWNER', e($txt($d['sales_owner']))) !!}
                    {!! $field('Data Entry', e($txt($d['data_entry']))) !!}
                    {!! $field('MANAGEMENT', e($txt($d['management']))) !!}
                </dl></div>
            </section>
        </div>
    </div>

    @include('customers.c360.target-achievement')
</div>
@endif
