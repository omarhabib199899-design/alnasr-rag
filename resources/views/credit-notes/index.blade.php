<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-base text-gray-900 leading-tight">
            {{ __('Credit Notes') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-5">

            <x-page-header :crumbs="['Sales Data', 'Credit Notes']" title="Credit Notes" />

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <x-stat-card label="Total Credit Notes" :value="number_format($totalCount)" />
                <x-stat-card label="Total Amount" :value="number_format($totalAmount, 0).' SAR'" />
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-100 space-y-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 7l-7 14-7-14" /></svg>
                            {{ __('Credit Notes') }}
                        </h3>
                        <p class="text-xs text-gray-500 mt-0.5">
                            Every credit note issued, from the AR ledger.
                            @if ($lastUpdated)
                                Last updated {{ \Illuminate\Support\Carbon::parse($lastUpdated)->diffForHumans() }}.
                            @endif
                        </p>
                    </div>
                    <form method="GET" class="flex flex-wrap gap-3 items-end">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Search Credit Note # / Customer</label>
                            <input type="text" name="search" value="{{ $search }}" placeholder="e.g. CN-09473, CUS-9 or customer name"
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
                            <a href="{{ route('credit-notes.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Clear search</a>
                        @endif
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-[#eef3fa]">
                            <tr>
                                <x-sortable-th field="transaction_number">Credit Note #</x-sortable-th>
                                <x-sortable-th field="transaction_date">Date</x-sortable-th>
                                <x-sortable-th>Customer #</x-sortable-th>
                                <x-sortable-th field="customer_name">Customer</x-sortable-th>
                                <x-sortable-th field="statement_amount" align="right">Amount</x-sortable-th>
                                <x-sortable-th field="updated_at">Synced</x-sortable-th>
                                <th class="px-5 py-2.5 text-center text-xs font-semibold text-gray-700 uppercase tracking-wide">View</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($creditNotes as $cn)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3 text-sm text-gray-900 font-mono">{{ $cn->transaction_number ?: '—' }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-500">{{ optional($cn->transaction_date ? \Illuminate\Support\Carbon::parse($cn->transaction_date) : null)->format('Y-m-d') ?? '—' }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-500">{{ $cn->customer_number ?: '—' }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-600">{{ $cn->customer_name ?: '—' }}</td>
                                    <td class="px-5 py-3 text-sm text-right text-gray-900 font-semibold">{{ number_format(abs((float) $cn->statement_amount), 2) }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-500 whitespace-nowrap" title="{{ $cn->updated_at }}">{{ $cn->updated_at ? \Illuminate\Support\Carbon::parse($cn->updated_at)->diffForHumans() : '—' }}</td>
                                    <td class="px-5 py-3 text-sm text-center">
                                        @if ($cn->transaction_number && isset($hasItems[$cn->transaction_number]))
                                            <a href="{{ route('credit-notes.show', $cn->transaction_number) }}"
                                               title="View credit note details"
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
                                    <td colspan="7" class="px-5 py-8 text-center text-sm text-gray-500">No credit notes found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-5 py-3 border-t border-gray-100">
                    {{ $creditNotes->links() }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
