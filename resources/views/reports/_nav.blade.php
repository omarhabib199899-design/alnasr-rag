@php
    $tabs = [
        'purchase-recommendation' => ['label' => 'Purchase Recommendation', 'route' => route('reports.index')],
        'sales' => ['label' => 'Sales', 'route' => route('reports.transactions', 'sales')],
        'purchase-orders' => ['label' => 'Purchase Orders', 'route' => route('reports.transactions', 'purchase-orders')],
        'invoices' => ['label' => 'Invoices', 'route' => route('reports.transactions', 'invoices')],
        'returns' => ['label' => 'Returns', 'route' => route('reports.transactions', 'returns')],
        'warehouses' => ['label' => 'Warehouse Stock', 'route' => route('reports.warehouses')],
        'sfda-price-checks' => ['label' => 'SFDA Price Checks', 'route' => route('reports.sfda-price-checks')],
        'sfda-changes' => ['label' => 'SFDA Changes', 'route' => route('reports.sfda-changes')],
        'sfda-market-changes' => ['label' => 'SFDA Market Changes', 'route' => route('reports.sfda-market-changes')],
    ];
    $active = $active ?? 'purchase-recommendation';
@endphp

<div class="bg-white rounded-lg border border-gray-200 px-2 pt-2">
    <div class="flex flex-wrap gap-1 border-b border-gray-100">
        @foreach ($tabs as $key => $tab)
            <a href="{{ $tab['route'] }}"
               @class([
                   'px-4 py-2.5 text-sm font-medium border-b-2 -mb-px',
                   'border-gray-900 text-gray-900' => $active === $key,
                   'border-transparent text-gray-500 hover:text-gray-700' => $active !== $key,
               ])>
                {{ $tab['label'] }}
            </a>
        @endforeach
    </div>
</div>
