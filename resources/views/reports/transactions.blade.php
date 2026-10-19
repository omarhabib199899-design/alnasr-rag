<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-base text-gray-900 leading-tight">
            {{ __('Reports') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[1600px] mx-auto sm:px-6 lg:px-8 space-y-5">

            <x-page-header :crumbs="['Reports', $label]" :title="$label" />

            @include('reports._nav', ['active' => $type])

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <x-stat-card label="Total Rows" :value="number_format($totalCount)" />
            </div>

            @php
                $carryParams = array_filter(['search' => $search, 'date_from' => $dateFrom, 'date_to' => $dateTo]);
            @endphp

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-100 space-y-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2" /></svg>
                            {{ $label }}
                        </h3>
                        <p class="text-xs text-gray-500 mt-0.5">
                            Every synced {{ strtolower($label) }} line item from Zoho.
                            @if ($lastUpdated)
                                Last updated {{ \Illuminate\Support\Carbon::parse($lastUpdated)->diffForHumans() }}.
                            @endif
                        </p>
                    </div>
                    <form method="GET" class="flex flex-wrap gap-3 items-end">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 uppercase mb-1">
                                Search SKU / Document # / Customer / Item @if ($type === 'invoices') / Invoice ID / Warehouse / City @endif
                            </label>
                            <input type="text" name="search" value="{{ $search }}"
                                   placeholder="{{ $type === 'invoices' ? 'e.g. 1023, 84438, customer name, JEDDAH or 6850004315004849' : 'e.g. 1023, 84438 or customer name' }}"
                                   class="rounded-md border-gray-300 text-sm focus:border-gray-400 focus:ring-gray-400">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 uppercase mb-1">From</label>
                            <input type="date" name="date_from" value="{{ $dateFrom }}"
                                   class="rounded-md border-gray-300 text-sm focus:border-gray-400 focus:ring-gray-400">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 uppercase mb-1">To</label>
                            <input type="date" name="date_to" value="{{ $dateTo }}"
                                   class="rounded-md border-gray-300 text-sm focus:border-gray-400 focus:ring-gray-400">
                        </div>
                        @if ($status)
                            <input type="hidden" name="status" value="{{ $status }}">
                        @endif
                        <button type="submit" class="px-4 py-2 bg-gray-900 text-white text-sm font-semibold rounded-md hover:bg-gray-700">
                            Filter
                        </button>
                        @if ($search || $dateFrom || $dateTo)
                            <a href="{{ route('reports.transactions', array_filter([$type, 'status' => $status])) }}"
                               class="text-sm text-gray-500 hover:text-gray-700">Clear search</a>
                        @endif
                    </form>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('reports.transactions', array_merge([$type], $carryParams)) }}"
                           @class(['px-3 py-1.5 rounded-full text-xs font-semibold', 'bg-gray-900 text-white' => ! $status, 'bg-gray-100 text-gray-700 hover:bg-gray-200' => $status])>
                            All ({{ $statusCounts->sum() }})
                        </a>
                        @foreach ($statusCounts->keys() as $statusOption)
                            <a href="{{ route('reports.transactions', array_merge([$type], $carryParams, ['status' => $statusOption])) }}"
                               @class([
                                   'px-3 py-1.5 rounded-full text-xs font-semibold',
                                   'bg-gray-900 text-white' => $status === $statusOption,
                                   'bg-gray-100 text-gray-700 hover:bg-gray-200' => $status !== $statusOption,
                               ])>
                                {{ str($statusOption)->title() }} ({{ $statusCounts->get($statusOption, 0) }})
                            </a>
                        @endforeach
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-[#eef3fa]">
                            <tr>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">SKU</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Document #</th>
                                @if ($type === 'invoices')
                                    <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Invoice ID</th>
                                @endif
                                @if ($isPurchaseOrders)
                                    <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Vendor</th>
                                    <th class="px-3 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Rate</th>
                                    <th class="px-3 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Discount %</th>
                                @else
                                    <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Customer</th>
                                @endif
                                @if ($type === 'invoices')
                                    <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Warehouse</th>
                                @endif
                                <th class="px-3 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Quantity</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Date</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Status</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Synced</th>
                                <th class="px-3 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">View</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($items as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-3 py-3 text-sm text-gray-900 whitespace-nowrap">{{ $row->sku }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">
                                        @if ($row->document_number)
                                            <a href="{{ route('reports.document', [$type, $row->document_number]) }}"
                                               class="text-gray-700 underline decoration-dotted hover:text-gray-900">
                                                {{ $row->document_number }}
                                            </a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    @if ($type === 'invoices')
                                        <td class="px-3 py-3 text-sm text-gray-500 font-mono whitespace-nowrap">{{ $row->zoho_document_id ?? $row->extra['zoho_invoice_id'] ?? '—' }}</td>
                                    @endif
                                    @if ($isPurchaseOrders)
                                        <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $row->vendor_name ?? '—' }}</td>
                                        <td class="px-3 py-3 text-sm text-right text-gray-500 whitespace-nowrap">{{ $row->rate !== null ? number_format($row->rate, 2) : '—' }}</td>
                                        <td class="px-3 py-3 text-sm text-right text-gray-500 whitespace-nowrap">{{ $row->discount_percent !== null ? number_format($row->discount_percent, 2) : '—' }}</td>
                                    @else
                                        <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $row->customer_name ?? '—' }}</td>
                                    @endif
                                    @if ($type === 'invoices')
                                        <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $row->warehouse ?? '—' }}</td>
                                    @endif
                                    <td class="px-3 py-3 text-sm text-right text-gray-500 whitespace-nowrap">{{ number_format($row->quantity) }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ optional($row->{$dateColumn})->format('Y-m-d') }}</td>
                                    <td class="px-3 py-3 text-sm whitespace-nowrap">
                                        <span class="px-2 py-1 rounded text-xs font-semibold bg-gray-100 text-gray-700 whitespace-nowrap">
                                            {{ str($row->status)->title() }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap" title="{{ $row->updated_at }}">{{ $row->updated_at ? \Illuminate\Support\Carbon::parse($row->updated_at)->diffForHumans() : '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-center whitespace-nowrap">
                                        @if ($row->document_number)
                                            <a href="{{ route('reports.document', [$type, $row->document_number]) }}"
                                               title="View invoice details"
                                               class="inline-flex items-center justify-center w-8 h-8 rounded-md text-gray-500 hover:text-gray-900 hover:bg-gray-100">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                </svg>
                                            </a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ ($isPurchaseOrders ? 8 : 6) + ($type === 'invoices' ? 2 : 0) + 2 }}" class="px-5 py-8 text-center text-sm text-gray-500">
                                        No {{ strtolower($label) }} data synced yet.
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
