<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-base text-gray-900 leading-tight">
            {{ __('Reports') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-5">

            <x-page-header :crumbs="['Reports', $label, 'Document #'.$documentNumber]" :title="'Document #'.$documentNumber" />

            @include('reports._nav', ['active' => $type])

            <div>
                <a href="{{ route('reports.transactions', $type) }}" class="text-sm text-gray-500 hover:text-gray-700">
                    &larr; Back to {{ $label }}
                </a>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 p-6">
                <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">
                            {{ $isPurchaseOrders ? 'Purchase Order' : ($type === 'returns' ? 'Return' : ($type === 'sales' ? 'Sales Order' : 'Invoice')) }}
                            #{{ $documentNumber }}
                        </h3>
                        <p class="text-sm text-gray-500 mt-1">
                            {{ $isPurchaseOrders ? 'Vendor' : 'Customer' }}: <strong>{{ $partyName ?? '—' }}</strong>
                        </p>
                        @if ($zohoInvoiceId)
                            <p class="text-sm text-gray-500 mt-1">
                                Invoice ID: <strong class="font-mono">{{ $zohoInvoiceId }}</strong>
                            </p>
                        @endif
                    </div>
                    <div class="text-right">
                        <div class="text-sm text-gray-500">
                            {{ optional($date)->format('Y-m-d') ?? '—' }}
                        </div>
                        <span class="inline-block mt-1 px-2 py-1 rounded text-xs font-semibold bg-gray-100 text-gray-700 whitespace-nowrap">
                            {{ str($status)->title() }}
                        </span>
                    </div>
                </div>

                <div class="mb-4">
                    @if ($sort === 'name')
                        <a href="{{ request()->fullUrlWithQuery(['sort' => null]) }}" class="inline-block text-sm px-3 py-1.5 rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">
                            Sorted by Name &mdash; click for SKU order
                        </a>
                    @else
                        <a href="{{ request()->fullUrlWithQuery(['sort' => 'name']) }}" class="inline-block text-sm px-3 py-1.5 rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">
                            Sort by Name (A&ndash;Z)
                        </a>
                    @endif
                </div>

                <div class="overflow-x-auto rounded-lg border border-gray-100">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-[#eef3fa]">
                            <tr>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">SKU</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Item</th>
                                @if ($type === 'invoices')
                                    <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Warehouse</th>
                                @endif
                                <th class="px-3 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Quantity</th>
                                <th class="px-3 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Price</th>
                                <th class="px-3 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Sub Total</th>
                                <th class="px-3 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Discount %</th>
                                <th class="px-3 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Discount Amt</th>
                                <th class="px-3 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">Total</th>
                                <th class="px-3 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wide whitespace-nowrap">More</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100" x-data="{ openRow: null }">
                            @foreach ($items as $row)
                                @php
                                    $extra = $row->extra ?? [];
                                    // Every remaining extra field not already
                                    // rendered as its own column, so nothing
                                    // captured for this row is ever silently
                                    // hidden — see the "More" toggle below.
                                    $shownExtraKeys = ['item_name', 'item_price', 'discount_percent', 'discount_amount', 'sub_total', 'total'];
                                    $remainingExtra = collect($extra)
                                        ->except(array_merge($shownExtraKeys, $documentLevelKeys))
                                        ->filter(fn ($v) => $v !== null && $v !== '')
                                        ->all();
                                @endphp
                                <tr class="hover:bg-gray-50">
                                    <td class="px-3 py-3 text-sm text-gray-900 whitespace-nowrap">{{ $row->sku }}</td>
                                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $extra['item_name'] ?? '—' }}</td>
                                    @if ($type === 'invoices')
                                        <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $row->warehouse ?? '—' }}</td>
                                    @endif
                                    <td class="px-3 py-3 text-sm text-right text-gray-500 whitespace-nowrap">{{ number_format($row->quantity) }}</td>
                                    <td class="px-3 py-3 text-sm text-right text-gray-500 whitespace-nowrap">
                                        {{ isset($extra['item_price']) ? number_format($extra['item_price'], 2) : (isset($row->rate) ? number_format($row->rate, 2) : '—') }}
                                    </td>
                                    <td class="px-3 py-3 text-sm text-right text-gray-500 whitespace-nowrap">
                                        {{ isset($extra['sub_total']) ? number_format($extra['sub_total'], 2) : '—' }}
                                    </td>
                                    <td class="px-3 py-3 text-sm text-right text-gray-500 whitespace-nowrap">
                                        {{ isset($extra['discount_percent']) ? number_format($extra['discount_percent'], 2) : (isset($row->discount_percent) ? number_format($row->discount_percent, 2) : '—') }}
                                    </td>
                                    <td class="px-3 py-3 text-sm text-right text-gray-500 whitespace-nowrap">
                                        {{ isset($extra['discount_amount']) ? number_format($extra['discount_amount'], 2) : '—' }}
                                    </td>
                                    <td class="px-3 py-3 text-sm text-right text-gray-500 whitespace-nowrap">
                                        {{ isset($extra['total']) ? number_format($extra['total'], 2) : '—' }}
                                    </td>
                                    <td class="px-3 py-3 text-sm text-center whitespace-nowrap">
                                        @if (! empty($remainingExtra))
                                            <button type="button" @click="openRow = openRow === {{ $loop->index }} ? null : {{ $loop->index }}" class="text-xs text-gray-500 underline decoration-dotted hover:text-gray-900">
                                                <span x-text="openRow === {{ $loop->index }} ? 'Hide' : 'More'"></span>
                                            </button>
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                                @if (! empty($remainingExtra))
                                    <tr x-show="openRow === {{ $loop->index }}" x-cloak style="display: none;">
                                        <td colspan="{{ $type === 'invoices' ? 10 : 9 }}" class="px-3 py-3 bg-gray-50 text-xs text-gray-600">
                                            <div class="flex flex-wrap gap-x-6 gap-y-1">
                                                @foreach ($remainingExtra as $key => $value)
                                                    <div><span class="font-medium text-gray-500">{{ str($key)->replace('_', ' ')->title() }}:</span> {{ is_scalar($value) ? $value : json_encode($value) }}</div>
                                                @endforeach
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="bg-gray-50 font-semibold">
                                <td class="px-3 py-3 text-sm text-gray-900" colspan="{{ $type === 'invoices' ? 3 : 2 }}">Total</td>
                                <td class="px-3 py-3 text-sm text-right text-gray-900">{{ number_format($totalQuantity) }}</td>
                                <td colspan="6"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            @if (! empty($documentExtra))
                @php
                    // taxes/custom_fields are arrays-of-pairs (see
                    // ZohoTransactionMapper::simplifyTaxes/simplifyCustomFields)
                    // rendered as their own mini-list; every other key here is
                    // a plain scalar (address strings, totals, dates, notes)
                    // rendered the same generic label:value way as the rest of
                    // this page.
                    $taxesExtra = $documentExtra['taxes'] ?? [];
                    $customFieldsExtra = $documentExtra['custom_fields'] ?? [];
                    $scalarDocumentExtra = collect($documentExtra)->except(['taxes', 'custom_fields'])->all();
                @endphp
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-4">Document Details (from Zoho)</h3>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 text-sm">
                        @foreach ($scalarDocumentExtra as $key => $value)
                            <div>
                                <div class="text-xs font-medium text-gray-500 uppercase">{{ str($key)->replace('_', ' ')->title() }}</div>
                                <div class="text-gray-900 mt-0.5">{{ is_scalar($value) ? $value : json_encode($value) }}</div>
                            </div>
                        @endforeach
                    </div>
                    @if (! empty($taxesExtra))
                        <div class="mt-4 pt-4 border-t border-gray-100">
                            <div class="text-xs font-medium text-gray-500 uppercase mb-2">Taxes</div>
                            <div class="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                                @foreach ($taxesExtra as $tax)
                                    <div>{{ $tax['name'] }}: <span class="text-gray-900">{{ number_format((float) ($tax['amount'] ?? 0), 2) }}</span></div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    @if (! empty($customFieldsExtra))
                        <div class="mt-4 pt-4 border-t border-gray-100">
                            <div class="text-xs font-medium text-gray-500 uppercase mb-2">Custom Fields</div>
                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 text-sm">
                                @foreach ($customFieldsExtra as $field)
                                    <div>
                                        <div class="text-xs font-medium text-gray-500 uppercase">{{ $field['label'] }}</div>
                                        <div class="text-gray-900 mt-0.5">{{ $field['value'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            @if ($invoiceDocument)
                @php
                    // Generic label:value dump of everything invoice_documents
                    // knows about this invoice — dedicated columns plus its
                    // own extra JSON (the rich Analytics-only fields:
                    // e-invoice status, prepared/reviewed/delivered-by,
                    // carton/pallet counts, total margin, etc.) — looped
                    // rather than hardcoded so nothing captured here is ever
                    // silently left off this page, whatever fields a future
                    // Analytics export or live sync adds.
                    $hiddenDocFields = ['id', 'created_at', 'updated_at', 'extra', 'zoho_invoice_id', 'invoice_number', 'zoho_customer_id'];
                    $docFields = collect($invoiceDocument->getAttributes())
                        ->except($hiddenDocFields)
                        ->filter(fn ($v) => $v !== null && $v !== '')
                        ->all();
                    $docExtra = collect($invoiceDocument->extra ?? [])
                        ->filter(fn ($v) => $v !== null && $v !== '')
                        ->all();
                @endphp
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-4">Document Details</h3>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 text-sm">
                        @foreach ($docFields as $key => $value)
                            <div>
                                <div class="text-xs font-medium text-gray-500 uppercase">{{ str($key)->replace('_', ' ')->title() }}</div>
                                <div class="text-gray-900 mt-0.5">{{ is_scalar($value) ? $value : json_encode($value) }}</div>
                            </div>
                        @endforeach
                        @foreach ($docExtra as $key => $value)
                            <div>
                                <div class="text-xs font-medium text-gray-500 uppercase">{{ str($key)->replace('_', ' ')->title() }}</div>
                                <div class="text-gray-900 mt-0.5">{{ is_scalar($value) ? $value : json_encode($value) }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
