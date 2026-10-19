{{-- General — our own tab (not in the Lovable portal, kept by the additive rule), restyled to their card design.
     Same fields as before, plus the Overview extras their page doesn't have (Last Sales Order, Last Payment, contact persons). --}}
@php
    use App\Support\C360Format as F;
    $v = fn ($x) => ($x === null || trim((string) $x) === '') ? F::DASH : (string) $x;
    $d = fn ($x) => $x ? F::dmy(is_string($x) ? $x : $x->format('Y-m-d')) : F::DASH;
    $groups = [
        ['info', 'Basic Information', [
            ['Customer Name', $v($customer->customer_name)], ['Customer Number', $v($customer->customer_number)],
            ['Status', $v($customer->status)], ['Account Type', $v($customer->account_type)], ['City', $v($customer->city)],
        ]],
        ['activity', 'Activity', [
            ['Last Sales Order', $d($lastSalesOrderDate)], ['Last Payment', $d($lastPaymentDate)],
        ]],
        ['phone', 'Contact', collect($customerExtra)->map(fn ($val, $k) => [str($k)->replace('_', ' ')->title()->toString(), is_scalar($val) ? (string) $val : json_encode($val)])->values()->all()],
        ['clock', 'System Information', [
            ['Created Time', $customer->zoho_created_time?->format('Y-m-d H:i') ?? F::DASH],
            ['Last Modified Time', $customer->zoho_last_modified_time?->format('Y-m-d H:i') ?? F::DASH],
            ['Opening Balance', $customer->opening_balance !== null ? number_format((float) $customer->opening_balance, 2) : F::DASH],
        ]],
    ];
@endphp
<div class="c3-stack">
    <div class="c3-grid2">
        @foreach ($groups as [$icon, $title, $fields])
            @continue(empty($fields))
            <section class="c3-card">
                <header class="c3-card-h"><i data-lucide="{{ $icon }}" class="ico-18"></i><h2>{{ $title }}</h2></header>
                <div class="c3-card-b"><dl class="c3-fields">
                    @foreach ($fields as [$label, $value])
                        <div class="c3-field"><dt>{{ $label }}</dt><dd><span dir="auto">{{ $value }}</span></dd></div>
                    @endforeach
                </dl></div>
            </section>
        @endforeach
    </div>

    <section class="c3-card">
        <header class="c3-card-h"><i data-lucide="users" class="ico-18"></i><h2>Contact Persons</h2></header>
        <div class="c3-card-b">
            @if ($contactPersons->isEmpty())
                <p class="c3-muted" style="padding: 8px 0">No contact persons recorded.</p>
            @else
                <div class="c3-scroll">
                    <table class="c3-tbl">
                        <thead><tr><th class="start">Name</th><th class="start">Email</th></tr></thead>
                        <tbody>
                            @foreach ($contactPersons as $p)
                                <tr>
                                    <td class="start" dir="auto">{{ trim(($p->first_name ?? '').' '.($p->last_name ?? '')) ?: F::DASH }}</td>
                                    <td class="start">{{ $p->email ?: F::DASH }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>
</div>
