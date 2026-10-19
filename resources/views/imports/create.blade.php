<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Upload Excel File') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                @if ($errors->any())
                    <div class="mb-4 rounded-md bg-red-50 p-4 text-sm text-red-800">
                        <ul class="list-disc list-inside">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('imports.store') }}" enctype="multipart/form-data" x-data="{ type: '{{ old('type', 'inventory') }}' }">
                    @csrf

                    <fieldset class="mb-4">
                        <legend class="text-sm font-medium text-gray-700 mb-2">What kind of file is this?</legend>
                        <div class="flex flex-wrap gap-4">
                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                <input type="radio" name="type" value="inventory" x-model="type" class="border-gray-300">
                                Inventory (stock snapshot)
                            </label>
                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                <input type="radio" name="type" value="sales" x-model="type" class="border-gray-300">
                                Sales / orders
                            </label>
                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                <input type="radio" name="type" value="purchase_orders" x-model="type" class="border-gray-300">
                                Purchase orders
                            </label>
                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                <input type="radio" name="type" value="returns" x-model="type" class="border-gray-300">
                                Returns
                            </label>
                        </div>
                    </fieldset>

                    <p class="text-sm text-gray-600 mb-4" x-show="type === 'inventory'">
                        Upload an inventory Excel or CSV file — a Zoho export or any other system's, as long as it has
                        SKU, name, stock, and price columns. Column headers don't need to match exactly — we'll auto-match
                        what we recognize and ask you to confirm anything unclear, once per source file type.
                    </p>
                    <p class="text-sm text-gray-600 mb-4" x-show="type === 'sales'" x-cloak>
                        Upload a sales/order export with SKU, quantity, and order date columns (a status/cancelled
                        column is optional). This powers "most ordered," "most cancelled," and "orders by month"
                        charts.
                    </p>
                    <p class="text-sm text-gray-600 mb-4" x-show="type === 'purchase_orders'" x-cloak>
                        Upload a purchase-order export with SKU, quantity, and order date columns (a status column —
                        ordered/received/cancelled — is optional). This powers "most purchased," "pending orders,"
                        and "purchases by month" charts.
                    </p>
                    <p class="text-sm text-gray-600 mb-4" x-show="type === 'returns'" x-cloak>
                        Upload a returns export with SKU, quantity, and return date columns (a reason column is
                        optional). This powers "most returned products" and "returns by month" charts.
                    </p>

                    <input type="file" name="file" accept=".xlsx,.xls,.csv" required
                           class="block w-full text-sm text-gray-900 border border-gray-300 rounded-lg cursor-pointer bg-gray-50 focus:outline-none">

                    <div class="mt-4 flex justify-end">
                        <x-primary-button>{{ __('Upload') }}</x-primary-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
