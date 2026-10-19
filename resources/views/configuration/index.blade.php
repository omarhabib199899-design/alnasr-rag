<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-base text-gray-900 leading-tight">
            {{ __('Configuration') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[1600px] mx-auto sm:px-6 lg:px-8 space-y-5">

            <x-page-header :crumbs="['Configuration']" title="Configuration" />

            <div class="bg-white rounded-lg border border-gray-200 p-5">
                <h3 class="text-sm font-semibold text-gray-900 mb-4">Zoho Connection</h3>
                @if ($connection)
                    <dl class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                        <div>
                            <dt class="text-xs font-medium text-gray-500 uppercase">Status</dt>
                            <dd class="mt-1">
                                <span class="px-2 py-1 rounded text-xs font-semibold bg-green-100 text-green-700">{{ $connection->status }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-gray-500 uppercase">Last Synced</dt>
                            <dd class="mt-1 text-gray-900">{{ optional($connection->last_synced_at)->format('Y-m-d H:i') ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-gray-500 uppercase">Last Error</dt>
                            <dd class="mt-1 text-gray-900">{{ $connection->last_error ?: '—' }}</dd>
                        </div>
                    </dl>
                @else
                    <p class="text-sm text-gray-500">
                        No connected Zoho account.
                        <a href="{{ route('zoho.connect') }}" class="underline decoration-dotted hover:text-gray-900">Connect now</a>.
                    </p>
                @endif
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="text-sm font-semibold text-gray-900">Hourly Syncs (via imports)</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-[#eef3fa]">
                            <tr>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Source</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Status</th>
                                <th class="px-5 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide">Rows</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Last Run</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($importSources as $source)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3 text-sm text-gray-900">{{ $source['label'] }}</td>
                                    <td class="px-5 py-3 text-sm">
                                        @if ($source['status'])
                                            <span @class([
                                                'px-2 py-1 rounded text-xs font-semibold',
                                                'bg-green-100 text-green-700' => $source['status'] === 'completed',
                                                'bg-amber-100 text-amber-700' => $source['status'] === 'processing',
                                                'bg-red-100 text-red-700' => $source['status'] === 'failed',
                                                'bg-gray-100 text-gray-700' => ! in_array($source['status'], ['completed', 'processing', 'failed'], true),
                                            ])>{{ $source['status'] }}</span>
                                        @else
                                            <span class="text-gray-400">Never run</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-sm text-right text-gray-500">{{ $source['row_count'] !== null ? number_format($source['row_count']) : '—' }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-500">{{ optional($source['last_synced'])->format('Y-m-d H:i') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="text-sm font-semibold text-gray-900">Analytics Syncs (Zoho Analytics Query Tables)</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-[#eef3fa]">
                            <tr>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Table</th>
                                <th class="px-5 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide">Rows</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Last Synced</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($standaloneSources as $source)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3 text-sm text-gray-900">{{ $source['label'] }}</td>
                                    @if ($source['missing'])
                                        <td colspan="2" class="px-5 py-3 text-sm text-gray-400">Table not created yet</td>
                                    @else
                                        <td class="px-5 py-3 text-sm text-right text-gray-500">{{ number_format($source['row_count']) }}</td>
                                        <td class="px-5 py-3 text-sm text-gray-500">{{ $source['last_synced'] ? \Illuminate\Support\Carbon::parse($source['last_synced'])->format('Y-m-d H:i') : '—' }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
