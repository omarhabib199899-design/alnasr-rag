<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Credit Notes') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div>
                <a href="{{ route('credit-notes.index') }}" class="text-sm text-gray-500 hover:text-gray-700">
                    &larr; Back to Credit Notes
                </a>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">Credit Note #{{ $documentNumber }}</h3>
                        <p class="text-sm text-gray-500 mt-1">Customer: <strong>{{ $customerName ?: '—' }}</strong></p>
                    </div>
                    <div class="text-right">
                        <div class="text-sm text-gray-500">
                            {{ $date ? \Illuminate\Support\Carbon::parse($date)->format('Y-m-d') : '—' }}
                        </div>
                        @if ($status)
                            <span class="inline-block mt-1 px-2 py-1 rounded text-xs font-semibold bg-gray-100 text-gray-700 whitespace-nowrap">
                                {{ str($status)->title() }}
                            </span>
                        @endif
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">SKU</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Item Name</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Warehouse</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Quantity</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Item Price</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach ($items as $item)
                                @php $extra = json_decode($item->extra ?? '{}', true) ?: []; @endphp
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-2 text-sm text-gray-900 font-mono">{{ $item->sku ?: '—' }}</td>
                                    <td class="px-4 py-2 text-sm text-gray-600">{{ $extra['item_name'] ?? '—' }}</td>
                                    <td class="px-4 py-2 text-sm text-gray-600">{{ $item->warehouse ?: '—' }}</td>
                                    <td class="px-4 py-2 text-sm text-right text-gray-900">{{ $item->quantity !== null ? number_format($item->quantity) : '—' }}</td>
                                    <td class="px-4 py-2 text-sm text-right text-gray-600">{{ isset($extra['item_price']) ? number_format((float) $extra['item_price'], 2) : '—' }}</td>
                                    <td class="px-4 py-2 text-sm text-right text-gray-900 font-medium">{{ isset($extra['total']) ? number_format((float) $extra['total'], 2) : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
