{{-- Customer 360 — exact copy of the Al-Nasr Lovable portal's page (src/routes/sales.customers.$customerId.tsx):
     header (breadcrumb, name, customer number, Back), icon tab bar, one panel per tab. Their 10 tabs, then our own
     General and Change History (kept — additive rule). --}}
@php use App\Support\C360Format as F; @endphp
<x-app-layout>
    @include('customers.c360.styles')
    @include('customers.c360.scripts')

    @php
        $urls = [route('customers.show360.ar-details', $customer), route('customers.show360.insight-items', $customer), route('customers.show360.expiry-items', $customer)];
    @endphp
    <div class="c3" x-data="c360Page(@js($activeTab), @js($urls[0]), @js($urls[1]), @js($urls[2]))">
        <header class="c3-head">
            <div style="min-width: 0; flex: 1">
                <nav class="c3-crumbs" aria-label="Breadcrumb">
                    <span>Sales Data</span>
                    <i data-lucide="chevron-right"></i>
                    <a href="{{ route('customers.index') }}">Customers</a>
                    <i data-lucide="chevron-right"></i>
                    <span class="cur" dir="auto">{{ $overview['customer_name'] ?? $customer->customer_name ?? F::DASH }}</span>
                </nav>
                <h1 class="c3-title" dir="auto">{{ $overview['customer_name'] ?? $customer->customer_name ?? F::DASH }}</h1>
                <p class="c3-sub">Customer Number: <bdi dir="ltr"><b class="tnum">{{ $overview['customer_number'] ?? $customer->customer_number ?? F::DASH }}</b></bdi></p>
            </div>
            <a href="{{ route('customers.index') }}" class="c3-back"><i data-lucide="arrow-left" class="ico"></i>Back to Customers</a>
        </header>

        <div class="c3-tabsbar">
            <div class="c3-tabs" role="tablist" aria-label="Customer 360 sections">
                @foreach ($tabs as $key => [$label, $icon])
                    <button type="button" role="tab" class="c3-tab" :class="tab === '{{ $key }}' && 'on'" :aria-selected="tab === '{{ $key }}'" @click="setTab('{{ $key }}')">
                        <i data-lucide="{{ $icon }}" class="ico"></i>{{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        @foreach (array_keys($tabs) as $key)
            <div class="c3-panel" role="tabpanel" x-show="tab === '{{ $key }}'" @if ($key !== $activeTab) x-cloak @endif data-tab="{{ $key }}">
                @switch($key)
                    @case('overview') @include('customers.c360.tab-overview') @break
                    @case('sales') @include('customers.c360.tab-sales') @break
                    @case('credit_note') @include('customers.c360.tab-credit-note') @break
                    @case('sales_orders') @include('customers.c360.tab-sales-orders') @break
                    @case('profitability') @include('customers.c360.tab-profitability') @break
                    @case('collection') @include('customers.c360.tab-collection') @break
                    @case('ar') @include('customers.c360.tab-ar') @break
                    @case('credit_limit') @include('customers.c360.tab-credit-limit') @break
                    @case('item_performance') @include('customers.c360.tab-item-performance') @break
                    @case('insights') @include('customers.c360.tab-insights') @break
                    @case('general') @include('customers.c360.tab-general') @break
                    @case('history') @include('customers.c360.tab-history') @break
                @endswitch
            </div>
        @endforeach

        @include('customers.c360.overlays')
    </div>

</x-app-layout>
