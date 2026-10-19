<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-base text-gray-900 leading-tight">
            {{ __('Reports') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[1600px] mx-auto sm:px-6 lg:px-8 space-y-5">

            <x-page-header :crumbs="['Reports', 'Purchase Recommendations']" title="Purchase Recommendations" />

            @include('reports._nav', ['active' => 'purchase-recommendation'])

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <x-stat-card label="Total Rows" :value="number_format($totalCount)" />
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-100 space-y-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                            {{ __('Purchase Recommendations') }}
                        </h3>
                        <p class="text-xs text-gray-500 mt-0.5">
                            Stock status, suggested purchase quantity, and vendor comparison per SKU, based on 6-month sales velocity.
                            @if ($lastUpdated)
                                Last updated {{ \Illuminate\Support\Carbon::parse($lastUpdated)->diffForHumans() }}.
                            @endif
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('reports.index') }}"
                           @class(['px-3 py-1.5 rounded-full text-xs font-semibold', 'bg-gray-900 text-white' => ! $status, 'bg-gray-100 text-gray-700 hover:bg-gray-200' => $status])>
                            All ({{ $statusCounts->sum() }})
                        </a>
                        @foreach (['CRITICAL', 'OUT OF STOCK', 'LOW', 'OK', 'OVERSTOCKED', 'NO MOVEMENT'] as $statusOption)
                            <a href="{{ route('reports.index', ['status' => $statusOption]) }}"
                               @class([
                                   'px-3 py-1.5 rounded-full text-xs font-semibold',
                                   'bg-gray-900 text-white' => $status === $statusOption,
                                   'bg-gray-100 text-gray-700 hover:bg-gray-200' => $status !== $statusOption,
                               ])>
                                {{ $statusOption }} ({{ $statusCounts->get($statusOption, 0) }})
                            </a>
                        @endforeach
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-[#eef3fa]">
                            <tr>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">SKU</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Item</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap" title="Highest-stock branch for this SKU, from manually imported Zoho Stock Summary exports — see the Warehouse Stock tab">Top Warehouse</th>
                                <th class="px-3 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Stock</th>
                                <th class="px-3 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Sold (6mo)</th>
                                <th class="px-3 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap" title="Estimated from sales/returns/purchase history, not a real daily stock log — approximate">Stockouts (1yr)</th>
                                <th class="px-3 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Pending PO</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Status</th>
                                <th class="px-3 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Suggested</th>
                                <th class="px-3 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap" title="Approximate: today's price/cost × 6-month sales volume, not actual historical margins">Est. Profit (6mo)</th>
                                <th class="px-3 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap" title="Distinct customers who bought this SKU in the last 6 months — undercounted until the Zoho sync backfill finishes">Customers</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap" title="Name-pattern match on 'صيدلية'/'pharmacy' — not an official Zoho customer type">Top Pharmacy Order</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Most Purchased Vendor</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Lowest Price Vendor</th>
                                <th class="px-3 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Value</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($recommendations as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-3 py-3 text-sm text-gray-900 whitespace-nowrap">{{ $row->sku }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $row->item_name }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">
                                        {{ $row->top_warehouse_name ?? '—' }}
                                        @if ($row->top_warehouse_stock)
                                            <span class="text-gray-400">({{ number_format($row->top_warehouse_stock) }})</span>
                                        @endif
                                        @if ($row->warehouse_count > 1)
                                            <span class="text-xs text-gray-400">+{{ $row->warehouse_count - 1 }} more</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-sm text-right text-gray-500 whitespace-nowrap">{{ number_format($row->current_stock) }}</td>
                                    <td class="px-3 py-3 text-sm text-right text-gray-500 whitespace-nowrap">{{ number_format($row->sold_6mo) }}</td>
                                    <td class="px-3 py-3 text-sm text-right text-gray-500 whitespace-nowrap">{{ $row->stockout_count_1yr ?? '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-right text-gray-500 whitespace-nowrap">{{ number_format($row->pending_po) }}</td>
                                    <td class="px-3 py-3 text-sm whitespace-nowrap">
                                        <span @class([
                                            'px-2 py-1 rounded text-xs font-semibold whitespace-nowrap',
                                            'bg-red-100 text-red-800' => in_array($row->stock_status, ['CRITICAL', 'OUT OF STOCK']),
                                            'bg-yellow-100 text-yellow-800' => $row->stock_status === 'LOW',
                                            'bg-green-100 text-green-800' => $row->stock_status === 'OK',
                                            'bg-blue-100 text-blue-800' => $row->stock_status === 'OVERSTOCKED',
                                            'bg-gray-100 text-gray-600' => $row->stock_status === 'NO MOVEMENT',
                                        ])>{{ $row->stock_status }}</span>
                                    </td>
                                    <td class="px-3 py-3 text-sm text-right text-gray-500 whitespace-nowrap">{{ number_format($row->suggested_purchase) }}</td>
                                    <td class="px-3 py-3 text-sm text-right text-gray-500 whitespace-nowrap">{{ $row->estimated_profit_6mo !== null ? number_format($row->estimated_profit_6mo, 2) : '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-right text-gray-500 whitespace-nowrap">{{ $row->customer_count_6mo ?? '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">
                                        {{ $row->top_pharmacy_name ?? '—' }}
                                        @if ($row->top_pharmacy_order_qty)
                                            <span class="text-gray-400">({{ number_format($row->top_pharmacy_order_qty) }})</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $row->most_purchased_vendor ?? '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">
                                        {{ $row->lowest_price_vendor ?? '—' }}
                                        @if ($row->lowest_price_value)
                                            <span class="text-gray-400">({{ number_format($row->lowest_price_value, 2) }})</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-sm text-right text-gray-500 whitespace-nowrap">{{ $row->value ? number_format($row->value, 2) : '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="15" class="px-5 py-8 text-center text-sm text-gray-500">
                                        No recommendations yet — run <code>php artisan reports:purchase-recommendations --push</code> to compute them.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-5 py-3 border-t border-gray-100">
                    {{ $recommendations->links() }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
