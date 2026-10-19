<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-base text-gray-900 leading-tight">{{ __('Activity Log') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[1600px] mx-auto sm:px-6 lg:px-8 space-y-5">

            <x-page-header :crumbs="['Activity Log']" title="Activity Log" />

            <form method="GET" class="flex items-end gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-500 uppercase mb-1">User</label>
                    <select name="user_id" onchange="this.form.submit()"
                            class="rounded-md border-gray-300 text-sm focus:border-gray-400 focus:ring-gray-400">
                        <option value="">All users</option>
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}" @selected((string) $selectedUserId === (string) $u->id)>{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
            </form>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" /></svg>
                        {{ __('Login Activity') }}
                    </h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-[#eef3fa]">
                            <tr>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">When</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">User</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Event</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">IP Address</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($logins as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3 text-sm text-gray-500">{{ \Illuminate\Support\Carbon::parse($row->created_at)->format('Y-m-d H:i') }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-900">{{ $row->user_name ?? $row->email }}</td>
                                    <td class="px-5 py-3 text-sm">
                                        <span @class([
                                            'px-2 py-1 rounded text-xs font-semibold',
                                            'bg-green-100 text-green-700' => $row->event === 'login',
                                            'bg-gray-100 text-gray-700' => $row->event === 'logout',
                                            'bg-red-100 text-red-700' => $row->event === 'failed',
                                        ])>{{ ucfirst($row->event) }}</span>
                                    </td>
                                    <td class="px-5 py-3 text-sm text-gray-500 font-mono">{{ $row->ip_address ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-5 py-8 text-center text-sm text-gray-500">No login activity yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-5 py-3 border-t border-gray-100">{{ $logins->links() }}</div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" /></svg>
                        {{ __('Actions (Edits/Creates/Deletes)') }}
                    </h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-[#eef3fa]">
                            <tr>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">When</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">User</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Method</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Action</th>
                                <th class="px-5 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($actions as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3 text-sm text-gray-500">{{ \Illuminate\Support\Carbon::parse($row->created_at)->format('Y-m-d H:i') }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-900">{{ $row->user_name ?? '—' }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-500 font-mono">{{ $row->method }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-500">{{ $row->description ?? $row->url }}</td>
                                    <td class="px-5 py-3 text-sm text-right">
                                        <span @class(['font-semibold text-red-600' => $row->status_code >= 400, 'text-gray-500' => $row->status_code < 400])>
                                            {{ $row->status_code }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-5 py-8 text-center text-sm text-gray-500">No actions logged yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-5 py-3 border-t border-gray-100">{{ $actions->links() }}</div>
            </div>

        </div>
    </div>
</x-app-layout>
