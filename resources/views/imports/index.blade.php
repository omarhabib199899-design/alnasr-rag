<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-base text-gray-900 leading-tight">
            {{ __('Imports') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[1600px] mx-auto sm:px-6 lg:px-8 space-y-5">

            <x-page-header :crumbs="['Imports']" title="Imports" />

            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                            {{ __('Imports') }}
                        </h3>
                        <p class="text-xs text-gray-500 mt-0.5">Every Excel file uploaded to the dashboard.</p>
                    </div>
                    <a href="{{ route('imports.create') }}" class="px-4 py-2 bg-gray-900 text-white text-sm font-semibold rounded-md hover:bg-gray-700">
                        {{ __('Upload Excel File') }}
                    </a>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-[#eef3fa]">
                            <tr>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">File</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Type</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Status</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Rows</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Uploaded</th>
                                <th class="px-5 py-4"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($imports as $import)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3 text-sm text-gray-900">
                                        <a href="{{ route('imports.show', $import) }}" class="text-blue-700 hover:underline">{{ $import->original_filename }}</a>
                                    </td>
                                    <td class="px-5 py-3 text-sm text-gray-500">{{ str($import->type)->replace('_', ' ')->title() }}</td>
                                    <td class="px-5 py-3 text-sm">
                                        <span @class([
                                            'px-2 py-1 rounded text-xs font-semibold',
                                            'bg-green-100 text-green-800' => $import->status === 'completed',
                                            'bg-red-100 text-red-800' => $import->status === 'failed',
                                            'bg-yellow-100 text-yellow-800' => ! in_array($import->status, ['completed', 'failed']),
                                        ])>{{ $import->status }}</span>
                                    </td>
                                    <td class="px-5 py-3 text-sm text-gray-500">{{ $import->row_count ?? '—' }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-500">{{ $import->created_at->diffForHumans() }}</td>
                                    <td class="px-5 py-3 text-sm text-right">
                                        <form method="post" action="{{ route('imports.destroy', $import) }}"
                                              onsubmit="return confirm('Delete this import and all its rows? This cannot be undone.');">
                                            @csrf
                                            @method('delete')
                                            <button type="submit" class="text-red-600 hover:underline">{{ __('Delete') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-8 text-center text-sm text-gray-500">
                                        No imports yet. Upload an inventory or sales Excel file to get started.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-5 py-3 border-t border-gray-100">
                    {{ $imports->links() }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
