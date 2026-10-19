<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-base text-gray-900 leading-tight">
            {{ __('Sales Owners') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-5">

            <x-page-header :crumbs="['Sales Data', 'Sales Owners']" title="Sales Owners" />

            <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                <x-stat-card label="Total Owners" :value="number_format($totalOwners)" />
                <x-stat-card label="Customers" :value="number_format($totalCustomersWithOwners)" />
                <x-stat-card label="Total Outstanding" :value="number_format($totalOutstanding, 0).' SAR'" />
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-1.5">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="text-blue-600"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><path d="M16 3.128a4 4 0 0 1 0 7.744"></path><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><circle cx="9" cy="7" r="4"></circle></svg>
                            {{ __('Sales Owners') }}
                        </h3>
                        <p class="text-xs text-gray-500 mt-0.5">
                            Every sales owner with at least one assigned customer.
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
                                <x-sortable-th field="sales_owner">Name</x-sortable-th>
                                <x-sortable-th field="customer_count" align="right">Customers Count</x-sortable-th>
                                <x-sortable-th field="outstanding_balance" align="right">Outstanding Balance</x-sortable-th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($owners as $owner)
                                <tr onclick="window.location='{{ route('sales-owners.show', ['ownerName' => $owner->sales_owner]) }}'" class="cursor-pointer hover:bg-gray-50">
                                    <td class="px-5 py-3 text-sm text-blue-700 hover:underline font-medium">{{ $owner->sales_owner }}</td>
                                    <td class="px-5 py-3 text-sm text-right text-gray-600">{{ number_format($owner->customer_count) }}</td>
                                    <td class="px-5 py-3 text-sm text-right {{ $owner->outstanding_balance > 0 ? 'text-gray-900 font-semibold' : 'text-gray-400' }}">
                                        {{ number_format($owner->outstanding_balance, 0) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-5 py-8 text-center text-sm text-gray-500">No sales owners found yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-5 py-3 border-t border-gray-100 text-xs text-gray-400">
                    Showing {{ $owners->count() }} of {{ number_format($totalOwners) }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
