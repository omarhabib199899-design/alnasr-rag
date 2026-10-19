<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-base text-gray-900 leading-tight">
            {{ __('Payments Received') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[1600px] mx-auto sm:px-6 lg:px-8 space-y-5">

            <x-page-header :crumbs="['Sales Data', 'Payments Received']" title="Payments Received" />

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <x-stat-card label="Total Payments" :value="number_format($totalCount)" />
                <x-stat-card label="Total Amount" :value="number_format($totalAmount, 0).' SAR'" />
            </div>

            <form method="GET" class="bg-white rounded-lg border border-gray-200 p-5 space-y-3">
                <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-5 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Payment #</label>
                        <input type="text" name="payment_number" value="{{ $payment_number }}" placeholder="CPAY-15844"
                               class="w-full rounded-md border-gray-300 text-sm focus:border-gray-400 focus:ring-gray-400">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Customer #</label>
                        <input type="text" name="customer_number" value="{{ $customer_number }}" placeholder="CUS-1491"
                               class="w-full rounded-md border-gray-300 text-sm focus:border-gray-400 focus:ring-gray-400">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Customer Name</label>
                        <input type="text" name="customer_name" value="{{ $customer_name }}"
                               class="w-full rounded-md border-gray-300 text-sm focus:border-gray-400 focus:ring-gray-400">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Reference</label>
                        <input type="text" name="reference_number" value="{{ $reference_number }}" placeholder="JAZERA - 6 DYS"
                               class="w-full rounded-md border-gray-300 text-sm focus:border-gray-400 focus:ring-gray-400">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Mode</label>
                        <input type="text" name="payment_mode" value="{{ $payment_mode }}" placeholder="Bank Transfer, Cash, Check"
                               class="w-full rounded-md border-gray-300 text-sm focus:border-gray-400 focus:ring-gray-400">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Account</label>
                        <input type="text" name="account_name" value="{{ $account_name }}" placeholder="AL-JAZEERA"
                               class="w-full rounded-md border-gray-300 text-sm focus:border-gray-400 focus:ring-gray-400">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Description</label>
                        <input type="text" name="description" value="{{ $description }}"
                               class="w-full rounded-md border-gray-300 text-sm focus:border-gray-400 focus:ring-gray-400">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Invoice #</label>
                        <input type="text" name="invoice_number" value="{{ $invoice_number }}"
                               class="w-full rounded-md border-gray-300 text-sm focus:border-gray-400 focus:ring-gray-400">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 uppercase mb-1">From</label>
                        <input type="date" name="date_from" value="{{ $dateFrom }}"
                               class="w-full rounded-md border-gray-300 text-sm focus:border-gray-400 focus:ring-gray-400">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 uppercase mb-1">To</label>
                        <input type="date" name="date_to" value="{{ $dateTo }}"
                               class="w-full rounded-md border-gray-300 text-sm focus:border-gray-400 focus:ring-gray-400">
                    </div>
                </div>
                <div class="flex items-center gap-3 pt-1">
                    <button type="submit" class="px-4 py-2 bg-gray-900 text-white text-sm font-semibold rounded-md hover:bg-gray-700">
                        Filter
                    </button>
                    @if ($payment_number || $customer_number || $customer_name || $reference_number || $payment_mode || $account_name || $description || $invoice_number || $dateFrom || $dateTo)
                        <a href="{{ route('payments-received.legacy') }}" class="text-sm text-gray-500 hover:text-gray-700">Clear all filters</a>
                    @endif
                </div>
            </form>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                        {{ __('Payments Received') }}
                    </h3>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Every customer payment received, synced directly from Zoho.
                        @if ($lastUpdated)
                            Last updated {{ \Illuminate\Support\Carbon::parse($lastUpdated)->diffForHumans() }}.
                        @endif
                    </p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-[#eef3fa]">
                            <tr>
                                <x-sortable-th field="payment_number" padding="px-3 py-4" class="whitespace-nowrap">Payment #</x-sortable-th>
                                <x-sortable-th field="payment_date" padding="px-3 py-4" class="whitespace-nowrap">Date</x-sortable-th>
                                <x-sortable-th field="customer_number" padding="px-3 py-4" class="whitespace-nowrap">Customer #</x-sortable-th>
                                <x-sortable-th field="customer_name" padding="px-3 py-4" class="whitespace-nowrap">Customer</x-sortable-th>
                                <x-sortable-th field="reference_number" padding="px-3 py-4" class="whitespace-nowrap">Reference</x-sortable-th>
                                <x-sortable-th field="payment_mode" padding="px-3 py-4" class="whitespace-nowrap">Mode</x-sortable-th>
                                <x-sortable-th field="account_name" padding="px-3 py-4" class="whitespace-nowrap">Account</x-sortable-th>
                                <x-sortable-th field="description" padding="px-3 py-4" class="whitespace-nowrap">Description</x-sortable-th>
                                <x-sortable-th field="invoice_numbers" padding="px-3 py-4" class="whitespace-nowrap">Invoice #</x-sortable-th>
                                <x-sortable-th field="amount" padding="px-3 py-4" align="right" class="whitespace-nowrap">Amount</x-sortable-th>
                                <x-sortable-th field="unused_amount" padding="px-3 py-4" align="right" class="whitespace-nowrap">Unused</x-sortable-th>
                                <x-sortable-th field="currency_code" padding="px-3 py-4" class="whitespace-nowrap">Currency</x-sortable-th>
                                <th class="px-3 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Attachment</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($payments as $payment)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-3 py-3 text-sm text-gray-900 font-mono whitespace-nowrap">{{ $payment->payment_number ?: '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ optional($payment->payment_date ? \Illuminate\Support\Carbon::parse($payment->payment_date) : null)->format('Y-m-d') ?? '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $payment->customer_number ?: '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-600 whitespace-nowrap">{{ $payment->customer_name ?: '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $payment->reference_number ?: '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $payment->payment_mode ?: '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $payment->account_name ?: '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $payment->description ?: '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $payment->invoice_numbers ?: '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-right text-gray-900 font-semibold whitespace-nowrap">{{ number_format((float) $payment->amount, 2) }}</td>
                                    <td class="px-3 py-3 text-sm text-right text-gray-500 whitespace-nowrap">{{ $payment->unused_amount !== null ? number_format((float) $payment->unused_amount, 2) : '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $payment->currency_code ?: '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-center whitespace-nowrap">{{ $payment->has_attachment ? '✓' : '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="13" class="px-5 py-8 text-center text-sm text-gray-500">No payments found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-5 py-3 border-t border-gray-100">
                    {{ $payments->links() }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
