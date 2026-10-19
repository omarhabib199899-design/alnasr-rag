{{-- Entity Overview — their EntityOverview.tsx: Customer Status (5 counts + other status values), six distribution cards
     (Account Type / WH City with bars, the rest without), then the full-width Sales & Collection Target & Achievement. --}}
@php
    use App\Support\C360Format as F;
    $rows = collect($distributions);
    $of = fn (string $s) => $rows->where('section', $s)->values();
    $status = $of('status');
    $active = $status->first(fn ($r) => ! $r['is_missing'] && $r['value'] === 'Active')['customers'] ?? 0;
    $inactive = $status->first(fn ($r) => ! $r['is_missing'] && $r['value'] === 'Inactive')['customers'] ?? 0;
    $other = $status->filter(fn ($r) => $r['is_missing'] || ($r['value'] !== 'Active' && $r['value'] !== 'Inactive'));
    $total = $rows->first()['portfolio_customers'] ?? 0;
    $sections = [
        ['account_type', 'Account Type', 'building-2', true], ['wh_city', 'WH City', 'map-pin', true],
        ['payment_terms', 'Payment Terms', 'calendar-clock', false], ['management', 'Management', 'briefcase', false],
        ['sales_owner', 'Sales Owner', 'user-check', false], ['data_entry', 'Data Entry', 'clipboard-pen', false],
    ];
    $stats = [
        ['Total Customers', $total], ['Active', $active], ['Inactive', $inactive],
        ['Active Year', $activeCustomers['active_year'] ?? null], ['Active Month', $activeCustomers['active_month'] ?? null],
    ];
    $pct = fn ($v) => $v === null ? F::DASH : number_format($v, 2).'%';
    $missing = '<span class="c3-badge neutral" style="line-height: 18px">Missing / Unassigned</span>';
@endphp
<style>
    .c3-stats { display: grid; grid-template-columns: 1fr; gap: 12px; }
    @media (min-width: 640px) { .c3-stats { grid-template-columns: repeat(3, 1fr); } }
    @media (min-width: 1024px) { .c3-stats { grid-template-columns: repeat(5, 1fr); } }
    .c3-stat { border-radius: 8px; border: 1px solid var(--c360-border); background: var(--c360-card); padding: 12px 16px; box-shadow: 0 1px 2px rgba(20,36,59,.05); }
    .c3-stat .l { font-size: 12.5px; color: var(--c360-label); }
    .c3-stat .v { margin-top: 4px; font-size: 20px; font-weight: 600; color: var(--c360-heading); font-variant-numeric: tabular-nums; }
    .c3-dist { width: 100%; border-collapse: collapse; font-size: 13px; }
    .c3-dist thead tr { background: var(--c360-th); color: var(--c360-heading); }
    .c3-dist th { border-bottom: 1px solid var(--c360-border); padding: 8px 12px; font-weight: 600; }
    .c3-dist td { padding: 8px 12px; border-top: 1px solid var(--c360-border); color: var(--c360-value); }
    .c3-dist tbody tr:first-child td { border-top: 0; }
    .c3-bar { height: 6px; width: 80px; overflow: hidden; border-radius: 999px; background: var(--c360-th); }
    .c3-bar > div { height: 100%; border-radius: 999px; background: var(--c360-accent); }
</style>

@if ($rows->isEmpty())
    <p class="c3-muted">No customers found for this record.</p>
@else
<div class="c3-stack">
    <section class="c3-card">
        <header class="c3-card-h"><i data-lucide="users" class="ico-18"></i><h2>Customer Status</h2></header>
        <div class="c3-card-b">
            <div class="c3-stats">
                @foreach ($stats as [$l, $v])
                    <div class="c3-stat"><div class="l">{{ $l }}</div><div class="v"><bdi dir="ltr">{{ $v === null ? F::DASH : F::int($v) }}</bdi></div></div>
                @endforeach
            </div>
            @if ($other->isNotEmpty())
                <p style="margin: 8px 0 0; font-size: 12px; color: var(--c360-label)">Other status values:
                    @foreach ($other as $r)
                        {{ $loop->first ? '' : ' · ' }}@if ($r['is_missing'])Missing / Unassigned @else<bdi dir="auto">{{ $r['value'] }}</bdi>@endif (<bdi dir="ltr">{{ F::int($r['customers']) }}</bdi>)
                    @endforeach
                </p>
            @endif
        </div>
    </section>

    <div class="c3-grid2">
        @foreach ($sections as [$key, $title, $icon, $bars])
            @php $list = $of($key); @endphp
            <section class="c3-card">
                <header class="c3-card-h"><i data-lucide="{{ $icon }}" class="ico-18"></i><h2>{{ $title }}</h2></header>
                <div class="c3-card-b" style="flex: 1">
                    @if ($list->isEmpty())
                        <p style="padding: 16px 0; text-align: center; font-size: 13px; color: var(--c360-label)">No data</p>
                    @else
                        <table class="c3-dist">
                            <thead><tr>
                                <th style="text-align: start">Value</th>
                                <th style="width: 6rem; text-align: end">Customers</th>
                                <th style="width: {{ $bars ? '11rem' : '6rem' }}; text-align: end">%</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($list as $r)
                                    <tr>
                                        <td>{!! $r['is_missing'] ? $missing : '<span dir="auto" style="font-weight: 500">'.e($r['value']).'</span>' !!}</td>
                                        <td class="tnum" style="text-align: end"><bdi dir="ltr">{{ F::int($r['customers']) }}</bdi></td>
                                        <td class="tnum" style="text-align: end">
                                            @if ($bars)
                                                <div style="display: flex; align-items: center; justify-content: flex-end; gap: 8px">
                                                    <div class="c3-bar" aria-hidden="true"><div style="width: {{ max(0, min(100, $r['pct'] ?? 0)) }}%"></div></div>
                                                    <bdi dir="ltr" style="width: 3.5rem; text-align: end">{{ $pct($r['pct']) }}</bdi>
                                                </div>
                                            @else
                                                <bdi dir="ltr">{{ $pct($r['pct']) }}</bdi>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </section>
        @endforeach
    </div>

    @include('customers.c360.target-achievement')
</div>
@endif
