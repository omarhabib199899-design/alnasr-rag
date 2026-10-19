<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-base text-gray-900 leading-tight">
            {{ __('Reports') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[1600px] mx-auto sm:px-6 lg:px-8 space-y-5">

            <x-page-header :crumbs="['Reports', 'SFDA Price Checks']" title="SFDA Price Checks" />

            @include('reports._nav', ['active' => 'sfda-price-checks'])

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <x-stat-card label="Total Mismatches" :value="number_format($totalCount)" />
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-100 space-y-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            {{ __('SFDA Price Checks') }}
                        </h3>
                        <p class="text-xs text-gray-500 mt-0.5">
                            Items whose recorded price doesn't match SFDA's official regulated price.
                            @if ($lastChecked)
                                Last checked {{ \Illuminate\Support\Carbon::parse($lastChecked)->diffForHumans() }}.
                            @else
                                Never checked yet — run <code class="bg-gray-100 px-1 rounded">php artisan reports:check-sfda-prices</code>.
                            @endif
                        </p>
                    </div>
                    <form method="GET" class="flex flex-wrap gap-3 items-end">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Search SKU / Item / SFDA Reg #</label>
                            <input type="text" name="search" value="{{ $search }}" placeholder="e.g. 1023 or a drug name"
                                   class="rounded-md border-gray-300 text-sm focus:border-gray-400 focus:ring-gray-400">
                        </div>
                        <button type="submit" class="px-4 py-2 bg-gray-900 text-white text-sm font-semibold rounded-md hover:bg-gray-700">
                            Filter
                        </button>
                        @if ($search)
                            <a href="{{ route('reports.sfda-price-checks') }}" class="text-sm text-gray-500 hover:text-gray-700">Clear search</a>
                        @endif
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-[#eef3fa]">
                            <tr>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">SKU</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Item</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">SFDA Reg #</th>
                                <th class="px-5 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide">Our Price</th>
                                <th class="px-5 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide">Official Price</th>
                                <th class="px-5 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide">Difference</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($items as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3 text-sm text-gray-900">{{ $row->sku }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-500">{{ $row->item_name ?? '—' }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-500 font-mono">{{ $row->sfda_reg_no }}</td>
                                    <td class="px-5 py-3 text-sm text-right text-gray-500">{{ number_format($row->our_price, 2) }}</td>
                                    <td class="px-5 py-3 text-sm text-right text-gray-500">{{ number_format($row->official_price, 2) }}</td>
                                    <td class="px-5 py-3 text-sm text-right font-semibold {{ $row->price_difference > 0 ? 'text-red-600' : 'text-amber-600' }}">
                                        {{ $row->price_difference > 0 ? '+' : '' }}{{ number_format($row->price_difference, 2) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-8 text-center text-sm text-gray-500">
                                        No price mismatches found.
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
