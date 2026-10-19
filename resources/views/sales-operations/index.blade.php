<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-base text-gray-900 leading-tight">
            {{ __('Sales Operations') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[1600px] mx-auto sm:px-6 lg:px-8 space-y-5">

            <x-page-header :crumbs="['Sales Data', 'Sales Operations']" title="Sales Operations" />

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <x-stat-card label="Total Invoices" :value="number_format($totalCount)" />
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-100 space-y-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2" /></svg>
                            {{ __('Sales Operations') }}
                        </h3>
                        <p class="text-xs text-gray-500 mt-0.5">
                            One row per invoice, with its full operations fulfillment trail.
                            @if ($lastUpdated)
                                Last updated {{ \Illuminate\Support\Carbon::parse($lastUpdated)->diffForHumans() }}.
                            @endif
                        </p>
                    </div>
                    <form method="GET" class="flex flex-wrap gap-3 items-end">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Search Invoice #</label>
                            <input type="text" name="search" value="{{ $search }}" placeholder="e.g. 87026"
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
                        <button type="submit" class="px-4 py-2 bg-gray-900 text-white text-sm font-semibold rounded-md hover:bg-gray-700">
                            Filter
                        </button>
                        @if ($search || $dateFrom || $dateTo)
                            <a href="{{ route('sales-operations.legacy') }}" class="text-sm text-gray-500 hover:text-gray-700">Clear search</a>
                        @endif
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-[#eef3fa]">
                            <tr>
                                <x-sortable-th field="invoice_number" padding="px-3 py-4" class="whitespace-nowrap">Invoice No</x-sortable-th>
                                <x-sortable-th field="updated_at" padding="px-3 py-4" class="whitespace-nowrap">Synced</x-sortable-th>
                                <x-sortable-th field="invoice_date" padding="px-3 py-4" class="whitespace-nowrap">Date</x-sortable-th>
                                <x-sortable-th field="total" padding="px-3 py-4" align="right" class="whitespace-nowrap">Value</x-sortable-th>
                                <th class="px-3 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Qty</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Warehouse</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Prepared By</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Review By</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Delivery By</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Invoice Attached By</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Exit Date Time</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Delivery Date Time</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Customer Check</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">No of Errors After Delivery</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">RASD</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Data Entry</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Invoice Attachment Status</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Carton No</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Icebox No</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Pallets No</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($invoices as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-3 py-3 text-sm text-gray-900 font-mono whitespace-nowrap">{{ $row->invoice_number ?: '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap" title="{{ $row->updated_at }}">{{ $row->updated_at ? \Illuminate\Support\Carbon::parse($row->updated_at)->diffForHumans() : '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ optional($row->invoice_date ? \Illuminate\Support\Carbon::parse($row->invoice_date) : null)->format('Y-m-d') ?? '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-right text-gray-900 font-semibold whitespace-nowrap">{{ $row->total !== null ? number_format((float) $row->total, 2) : '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-right text-gray-500 whitespace-nowrap">{{ $row->qty !== null ? number_format((int) $row->qty) : '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $row->warehouses ?: '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $row->prepared_by ?: '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $row->review_by ?: '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $row->delivery_by ?: '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $row->invoice_attached_by ?: '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $row->exit_date_time ?: '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $row->delivery_date_time ?: '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $row->customer_check ?: '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $row->no_of_errors_after_delivery ?: '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $row->rasd ?: '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $row->data_entry ?: '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $row->invoice_attachment_status ?: '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $row->carton_no ?: '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $row->icebox_no ?: '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $row->pallets_no ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="19" class="px-5 py-8 text-center text-sm text-gray-500">No invoices found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-5 py-3 border-t border-gray-100">
                    {{ $invoices->links() }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
