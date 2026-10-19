<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-base text-gray-900 leading-tight">
            {{ __('Reports') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[1600px] mx-auto sm:px-6 lg:px-8 space-y-5">

            <x-page-header :crumbs="['Reports', 'SFDA Reg No Issues']" title="SFDA Reg No Issues" />

            @include('reports._nav', ['active' => 'sfda-reg-no-issues'])

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <x-stat-card label="Total Issues" :value="number_format($totalCount)" />
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-100 space-y-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                            {{ __('SFDA Reg No Issues') }}
                        </h3>
                        <p class="text-xs text-gray-500 mt-0.5">
                            Items whose SFDA registration number (as recorded on the item in Zoho) resolves to a different drug entirely at SFDA — not a price mismatch, a wrong reg no that needs correcting on the item in Zoho.
                            @if ($lastDetected)
                                Last checked {{ \Illuminate\Support\Carbon::parse($lastDetected)->diffForHumans() }}.
                            @endif
                        </p>
                    </div>
                    <form method="GET" class="flex flex-wrap gap-3 items-end">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Search SKU / Item / Reg No</label>
                            <input type="text" name="search" value="{{ $search }}" placeholder="e.g. 166 or EBETAXEL"
                                   class="rounded-md border-gray-300 text-sm focus:border-gray-400 focus:ring-gray-400">
                        </div>
                        <button type="submit" class="px-4 py-2 bg-gray-900 text-white text-sm font-semibold rounded-md hover:bg-gray-700">
                            Filter
                        </button>
                        @if ($search)
                            <a href="{{ route('reports.sfda-reg-no-issues') }}" class="text-sm text-gray-500 hover:text-gray-700">Clear search</a>
                        @endif
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-[#eef3fa]">
                            <tr>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">SKU</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Our Item Name</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">SFDA Reg No (on file)</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">SFDA Says This Is</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($items as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3 text-sm text-gray-900">{{ $row->sku }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-700">{{ $row->item_name }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-500 font-mono">{{ $row->sfda_reg_no }}</td>
                                    <td class="px-5 py-3 text-sm text-red-600">{{ $row->sfda_trade_name ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-5 py-8 text-center text-sm text-gray-500">
                                        No reg no issues found.
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
