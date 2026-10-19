{{-- Sales Orders — their SalesOrdersTabSection: status tabs (no "All"), total KPI. Customer mode: item-level rows, counter =
     distinct items. Entity mode ($isEntity): one row per Sales Order (EntityOrdersTable), counter = distinct Sales Orders. --}}
@php
    use App\Support\C360Format as F;
    $key = fn ($s) => $s === null ? '__null' : $s;
    $statuses = collect($soSummary);
    $ent = $isEntity ?? false;
    $lines = collect($ent ? $soOrders : $soLines)->groupBy(fn ($r) => $key($r['status']));
@endphp
@if ($statuses->isEmpty())
    <p class="c3-muted">No sales orders for this scope.</p>
@else
<section class="c3-card" style="box-shadow: 0 1px 2px rgba(0,0,0,.05)" x-data="{ so: @js($key($statuses[0]['status'])) }">
    <header class="c3-card-h"><i data-lucide="clipboard-list" class="ico-18"></i><h2>Sales Orders Not Invoiced</h2></header>
    <div style="border-bottom: 1px solid var(--c360-border); padding: 12px 16px">
        <div class="c3-sokpi">
            <span class="l">Total Uninvoiced Sales Value (SAR)</span>
            @foreach ($statuses as $s)
                <span class="v" x-show="so === @js($key($s['status']))" @if (! $loop->first) x-cloak @endif><bdi dir="ltr">{{ F::dec2($s['total']) }}</bdi></span>
            @endforeach
        </div>
    </div>
    <div class="c3-subtabs" role="tablist" aria-label="Sales order status">
        @foreach ($statuses as $s)
            <button type="button" role="tab" class="c3-subtab" :class="so === @js($key($s['status'])) && 'on'" @click="so = @js($key($s['status']))">
                <span dir="auto">{{ $s['status'] ?? F::DASH }}</span><span class="n"><bdi dir="ltr">({{ F::int($s[$ent ? 'order_count' : 'item_count']) }})</bdi></span>
            </button>
        @endforeach
    </div>
    <div class="c3-card-b">
        @foreach ($statuses as $s)
            @php $rows = $lines[$key($s['status'])] ?? collect(); @endphp
            <div x-show="so === @js($key($s['status']))" @if (! $loop->first) x-cloak @endif>
                @if ($rows->isEmpty())
                    <p style="padding: 16px 12px; font-size: 12.5px; color: var(--c360-label)">No sales orders for this status.</p>
                @elseif ($ent)
                    <div class="c3-scroll">
                        <table class="c3-tbl" style="min-width: 640px">
                            <thead><tr>
                                <th class="start">Order Date</th><th class="start">Sales Order Number</th><th class="start">Customer Name</th><th>Uninvoiced Sales Value</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr>
                                        <td class="start"><bdi dir="ltr">{{ F::slash($r['order_date']) }}</bdi></td>
                                        <td class="start"><bdi dir="ltr">{{ $r['so_number'] ?? F::DASH }}</bdi></td>
                                        <td class="start" style="white-space: normal"><span dir="auto">{{ $r['customer_name'] ?? F::DASH }}</span></td>
                                        <td><bdi dir="ltr">{{ F::dec2($r['value']) }}</bdi></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="c3-scroll">
                        <table class="c3-tbl" style="min-width: 900px">
                            <thead><tr>
                                <th class="start">Order Date</th><th class="start">Sales Order Number</th><th class="start">Item Name</th>
                                <th>Ordered Qty</th><th>Invoiced Qty</th><th>Uninvoiced Qty</th><th>Discount %</th><th>Uninvoiced Sales Value</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr>
                                        <td class="start"><bdi dir="ltr">{{ F::slash($r['order_date']) }}</bdi></td>
                                        <td class="start"><bdi dir="ltr">{{ $r['so_number'] ?? F::DASH }}</bdi></td>
                                        <td class="start" style="white-space: normal"><span dir="auto">{{ $r['item_name'] ?? F::DASH }}</span></td>
                                        <td><bdi dir="ltr">{{ F::qty($r['ordered_qty']) }}</bdi></td>
                                        <td><bdi dir="ltr">{{ F::qty($r['invoiced_qty']) }}</bdi></td>
                                        <td><bdi dir="ltr">{{ F::qty($r['uninvoiced_qty']) }}</bdi></td>
                                        <td><bdi dir="ltr">{{ $r['discount_pct'] ?? F::DASH }}</bdi></td>
                                        <td><bdi dir="ltr">{{ F::dec2($r['value']) }}</bdi></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endforeach
        @if ($ent)
            <p class="c3-note">Source: Zoho Analytics "Sales Orders Not Invoiced" — all dates. One row per Sales Order (sum of its item lines). Tab counter = distinct Sales Orders. Total = sum of Uninvoiced Sales Value for the selected status.</p>
        @else
            <p class="c3-note">Source: Zoho Analytics "Sales Orders Not Invoiced" — item lines as stored, all dates. Tab counter = distinct items (Item ID). Total = sum of Uninvoiced Sales Value for the selected status.</p>
        @endif
    </div>
</section>
@endif
