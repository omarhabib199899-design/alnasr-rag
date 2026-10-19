<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $import->original_filename }}
            </h2>
            <form method="post" action="{{ route('imports.destroy', $import) }}"
                  onsubmit="return confirm('Delete this import and all its rows? This cannot be undone.');">
                @csrf
                @method('delete')
                <button type="submit" class="text-sm text-red-600 hover:underline">{{ __('Delete Import') }}</button>
            </form>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <dl class="grid grid-cols-3 gap-4 text-sm">
                    <div>
                        <dt class="text-gray-500">Status</dt>
                        <dd class="font-medium">{{ $import->status }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Rows imported</dt>
                        <dd class="font-medium">{{ $import->row_count ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Uploaded</dt>
                        <dd class="font-medium">{{ $import->created_at->diffForHumans() }}</dd>
                    </div>
                </dl>

                @if ($import->status === 'failed')
                    <div class="mt-4 rounded-md bg-red-50 p-4 text-sm text-red-800">
                        {{ $import->error_message }}
                    </div>
                @endif

                @if (in_array($import->status, ['pending', 'processing']))
                    <div class="mt-4 rounded-md bg-yellow-50 p-4 text-sm text-yellow-800">
                        Processing your file… this page will refresh automatically.
                    </div>
                    <script>setTimeout(() => location.reload(), 3000);</script>
                @endif
            </div>

            @if ($sample->isNotEmpty())
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            @if (in_array($import->type, ['sales', 'purchase_orders', 'returns']))
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">SKU</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Quantity</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ $import->type === 'returns' ? 'Reason' : 'Status' }}</th>
                                </tr>
                            @else
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">SKU</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Stock</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Price</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Warehouse</th>
                                </tr>
                            @endif
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach ($sample as $item)
                                @if (in_array($import->type, ['sales', 'purchase_orders', 'returns']))
                                    <tr>
                                        <td class="px-4 py-2 text-sm text-gray-900">{{ $item->sku }}</td>
                                        <td class="px-4 py-2 text-sm text-gray-500">{{ $item->quantity }}</td>
                                        <td class="px-4 py-2 text-sm text-gray-500">{{ ($item->order_date ?? $item->transaction_date)->toDateString() }}</td>
                                        <td class="px-4 py-2 text-sm text-gray-500">{{ $item->status }}</td>
                                    </tr>
                                @else
                                    <tr>
                                        <td class="px-4 py-2 text-sm text-gray-900">{{ $item->sku }}</td>
                                        <td class="px-4 py-2 text-sm text-gray-900">{{ $item->name }}</td>
                                        <td class="px-4 py-2 text-sm text-gray-500">{{ $item->stock }}</td>
                                        <td class="px-4 py-2 text-sm text-gray-500">{{ $item->price }}</td>
                                        <td class="px-4 py-2 text-sm text-gray-500">{{ $item->category ?? '—' }}</td>
                                        <td class="px-4 py-2 text-sm text-gray-500">{{ $item->warehouse ?? '—' }}</td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                    <p class="text-xs text-gray-500 p-4">Showing first {{ $sample->count() }} rows.</p>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
