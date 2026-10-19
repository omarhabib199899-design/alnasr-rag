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
            'agent_name' => 'Agent',
        ];
    @endphp

    <div class="py-8">
        <div class="max-w-[1600px] mx-auto sm:px-6 lg:px-8 space-y-5">

            <x-page-header :crumbs="['Reports', 'SFDA Market Changes']" title="SFDA Market Changes" />

            @include('reports._nav', ['active' => 'sfda-market-changes'])

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <x-stat-card label="Total Changes Logged" :value="number_format($totalCount)" />
                <x-stat-card label="Items Tracked" :value="number_format($totalItemsTracked)" />
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-100 space-y-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                            {{ __('SFDA Market Changes') }}
                        </h3>
                        <p class="text-xs text-gray-500 mt-0.5">
                            Day-over-day changes across SFDA's entire drug database (as of {{ $lastSnapshotDate ? \Illuminate\Support\Carbon::parse($lastSnapshotDate)->format('Y-m-d') : '—' }}) — not limited to items we stock.
                            @if ($lastDetected)
                                Last detected {{ \Illuminate\Support\Carbon::parse($lastDetected)->diffForHumans() }}.
                            @endif
                        </p>
                    </div>
                    <form method="GET" class="flex flex-wrap gap-3 items-end">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Search Reg No / Item</label>
                            <input type="text" name="search" value="{{ $search }}" placeholder="e.g. 1-5210-18 or a drug name"
                                   class="rounded-md border-gray-300 text-sm focus:border-gray-400 focus:ring-gray-400">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Field</label>
                            <select name="field" class="rounded-md border-gray-300 text-sm focus:border-gray-400 focus:ring-gray-400">
                                <option value="">All</option>
                                @foreach ($fieldLabels as $key => $label)
                                    <option value="{{ $key }}" @selected($field === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="px-4 py-2 bg-gray-900 text-white text-sm font-semibold rounded-md hover:bg-gray-700">
                            Filter
                        </button>
                        @if ($search || $field)
                            <a href="{{ route('reports.sfda-market-changes') }}" class="text-sm text-gray-500 hover:text-gray-700">Clear</a>
                        @endif
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-[#eef3fa]">
                            <tr>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">SFDA Reg No</th>
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
                                    <td class="px-5 py-3 text-sm text-gray-900 font-mono">{{ $row->sfda_reg_no }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-500">{{ $row->trade_name ?? '—' }}</td>
                                    <td class="px-5 py-3 text-sm">
                                        <span class="px-2 py-1 rounded text-xs font-semibold bg-blue-100 text-blue-800 whitespace-nowrap">
                                            {{ $fieldLabels[$row->field_changed] ?? $row->field_changed }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-sm text-red-600 max-w-xs truncate" title="{{ $row->old_value }}">{{ $row->old_value ?? '—' }}</td>
                                    <td class="px-5 py-3 text-sm text-green-700 max-w-xs truncate" title="{{ $row->new_value }}">{{ $row->new_value ?? '—' }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-500 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($row->detected_date)->diffForHumans() }}</td>
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
