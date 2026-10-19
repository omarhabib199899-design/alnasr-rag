<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-base text-gray-900 leading-tight">
            {{ __('Reports') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[1600px] mx-auto sm:px-6 lg:px-8 space-y-5">

            <x-page-header :crumbs="['Reports', 'Warehouse Stock']" title="Warehouse Stock" />

            @include('reports._nav', ['active' => 'warehouses'])

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <x-stat-card :label="'SKUs in '.$selectedWarehouse" :value="number_format($skuCount)" />
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-100 space-y-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2M5 21H3m16 0h-2M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h0a1 1 0 011 1v5" /></svg>
                            {{ __('Warehouse Stock') }}
                        </h3>
                        <p class="text-xs text-gray-500 mt-0.5">
                            Per-SKU stock by branch, sorted highest stock first.
                        </p>
                    </div>
                    <form method="GET" class="flex flex-wrap gap-3 items-end">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Warehouse</label>
                            <select name="warehouse" onchange="this.form.submit()"
                                    class="rounded-md border-gray-300 text-sm focus:border-gray-400 focus:ring-gray-400">
                                @foreach ($warehouses as $w)
                                    <option value="{{ $w }}" @selected($w === $selectedWarehouse)>{{ $w }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Search SKU / Item</label>
                            <input type="text" name="search" value="{{ $search }}" placeholder="e.g. 1023 or CLOFEN"
                                   class="rounded-md border-gray-300 text-sm focus:border-gray-400 focus:ring-gray-400">
                        </div>
                        <button type="submit" class="px-4 py-2 bg-gray-900 text-white text-sm font-semibold rounded-md hover:bg-gray-700">
                            Filter
                        </button>
                        @if ($search)
                            <a href="{{ route('reports.warehouses', ['warehouse' => $selectedWarehouse]) }}"
                               class="text-sm text-gray-500 hover:text-gray-700">Clear search</a>
                        @endif
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-[#eef3fa]">
                            <tr>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">SKU</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Item</th>
                                <th class="px-5 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide">Closing Stock</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($items as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3 text-sm text-gray-900">{{ $row->sku }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-500">{{ $row->item_name ?? '—' }}</td>
                                    <td class="px-5 py-3 text-sm text-right font-semibold text-gray-900">{{ number_format($row->closing_stock) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-5 py-8 text-center text-sm text-gray-500">
                                        No warehouse stock data yet — import a Stock Summary Report export for this warehouse.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-5 py-3 border-t border-gray-100">
                    {{ $items->links() }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
