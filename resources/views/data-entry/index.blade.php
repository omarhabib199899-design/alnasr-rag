<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-base text-gray-900 leading-tight">
            {{ __('Data Entry') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-5">

            <x-page-header :crumbs="['Sales Data', 'Data Entry']" title="Data Entry" />

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <x-stat-card label="Total Groups" :value="number_format($totalEntries)" />
                <x-stat-card label="Total Customers" :value="number_format($totalCustomers)" />
                <x-stat-card label="With Data Entry" :value="number_format($totalCustomersWithEntries)" />
                <x-stat-card label="Total Outstanding" :value="number_format($totalOutstanding, 0).' SAR'" />
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-1.5">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="text-blue-600"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><path d="M16 3.128a4 4 0 0 1 0 7.744"></path><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><circle cx="9" cy="7" r="4"></circle></svg>
                            {{ __('Data Entry') }}
                        </h3>
                        <p class="text-xs text-gray-500 mt-0.5">
                            Every data entry person with at least one assigned customer.
                            @if ($lastUpdated)
                                Last synced {{ \Illuminate\Support\Carbon::parse($lastUpdated)->diffForHumans() }}.
                            @endif
                        </p>
                    </div>
                    <form method="GET" class="flex items-center gap-2">
                        <input type="text" name="search" value="{{ $search }}" placeholder="{{ __('Search by name...') }}"
                               class="rounded-md border-gray-300 text-sm focus:border-gray-400 focus:ring-gray-400" onchange="this.form.submit()">
                        <button type="submit" class="hidden" aria-hidden="true"></button>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-[#eef3fa]">
                            <tr>
                                <x-sortable-th field="data_entry">Name</x-sortable-th>
                                <x-sortable-th field="customer_count" align="right">Customers Count</x-sortable-th>
                                <x-sortable-th field="outstanding_balance" align="right">Outstanding Balance</x-sortable-th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($entries as $entry)
                                <tr onclick="window.location='{{ route('data-entry.show', ['dataEntryName' => $entry->data_entry]) }}'" class="cursor-pointer hover:bg-gray-50">
                                    <td class="px-5 py-3 text-sm text-blue-700 hover:underline font-medium">{{ $entry->data_entry }}</td>
                                    <td class="px-5 py-3 text-sm text-right text-gray-600">{{ number_format($entry->customer_count) }}</td>
                                    <td class="px-5 py-3 text-sm text-right {{ $entry->outstanding_balance > 0 ? 'text-gray-900 font-semibold' : 'text-gray-400' }}">
                                        {{ number_format($entry->outstanding_balance, 0) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-5 py-8 text-center text-sm text-gray-500">No data entry people found yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-5 py-3 border-t border-gray-100 text-xs text-gray-400">
                    Showing {{ $entries->count() }} of {{ number_format($totalEntries) }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
