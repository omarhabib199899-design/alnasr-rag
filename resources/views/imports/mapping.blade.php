<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Confirm Column Mapping') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
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

                <p class="text-sm text-gray-600 mb-6">
                    We haven't seen this exact column layout before (<strong>{{ $import->original_filename }}</strong>).
                    We've pre-filled our best guess for each column — review and adjust, then confirm.
                    This mapping will be remembered, so future uploads with the same columns won't ask again.
                </p>

                <form method="POST" action="{{ route('imports.mapping.confirm', $import) }}">
                    @csrf

                    <div class="space-y-4">
                        @foreach ($suggestions as $header => $guess)
                            <div class="grid grid-cols-2 gap-4 items-center">
                                <div class="text-sm font-medium text-gray-700">
                                    {{ $header }}
                                </div>
                                <select name="mapping[{{ $header }}]" class="border-gray-300 rounded-md shadow-sm w-full">
                                    @foreach ($options as $value => $label)
                                        <option value="{{ $value }}" @selected($guess === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-6 flex justify-end">
                        <x-primary-button>{{ __('Confirm & Import') }}</x-primary-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
