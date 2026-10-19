<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-base text-gray-900 leading-tight">
            {{ __('Reports') }}
        </h2>
    </x-slot>

    @php
        $fieldLabels = [
            'price' => 'Price',
            'storage_conditions' => 'Storage Conditions',
            'legal_status' => 'Legal Status',
            'marketing_status' => 'Marketing Status',
            'authorization_status' => 'Authorization Status',
        ];
    @endphp

    <div class="py-8">
        <div class="max-w-[1600px] mx-auto sm:px-6 lg:px-8 space-y-5">

            <x-page-header :crumbs="['Reports', 'SFDA Changes']" title="SFDA Changes" />

            @include('reports._nav', ['active' => 'sfda-changes'])

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <x-stat-card label="Total Detected" :value="number_format($totalCount)" />
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-100 space-y-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                            {{ __('SFDA Changes') }}
                        </h3>
                        <p class="text-xs text-gray-500 mt-0.5">
                            @if ($lastDetected)
                                Last detected {{ \Illuminate\Support\Carbon::parse($lastDetected)->diffForHumans() }}.
                            @else
                                None yet — the first check on each item just establishes a baseline, so changes only
                                start showing up from the second check onward.
                            @endif
                        </p>
                    </div>
                    <form method="GET" class="flex flex-wrap gap-3 items-end">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Search SKU / Item / Field</label>
                            <input type="text" name="search" value="{{ $search }}" placeholder="e.g. 1023, a drug name, or storage"
                                   class="rounded-md border-gray-300 text-sm focus:border-gray-400 focus:ring-gray-400">
                        </div>
                        <button type="submit" class="px-4 py-2 bg-gray-900 text-white text-sm font-semibold rounded-md hover:bg-gray-700">
                            Filter
                        </button>
                        @if ($search)
                            <a href="{{ route('reports.sfda-changes') }}" class="text-sm text-gray-500 hover:text-gray-700">Clear search</a>
                        @endif
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-[#eef3fa]">
                            <tr>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">SKU</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Item</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Field</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Old Value</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">New Value</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Detected</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($items as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3 text-sm text-gray-900">{{ $row->sku }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-500">{{ $row->item_name ?? '—' }}</td>
                                    <td class="px-5 py-3 text-sm">
                                        <span class="px-2 py-1 rounded text-xs font-semibold bg-blue-100 text-blue-800 whitespace-nowrap">
                                            {{ $fieldLabels[$row->field_changed] ?? $row->field_changed }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-sm text-red-600 max-w-xs truncate" title="{{ $row->old_value }}">{{ $row->old_value ?? '—' }}</td>
                                    <td class="px-5 py-3 text-sm text-green-700 max-w-xs truncate" title="{{ $row->new_value }}">{{ $row->new_value ?? '—' }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-500 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($row->detected_at)->diffForHumans() }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-8 text-center text-sm text-gray-500">
                                        No changes detected yet.
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
