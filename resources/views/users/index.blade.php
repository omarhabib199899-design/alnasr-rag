<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-base text-gray-900 leading-tight">
            {{ __('Users') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[1600px] mx-auto sm:px-6 lg:px-8 space-y-5">

            <x-page-header :crumbs="['Users']" title="Users" />

            @if (session('status'))
                <div class="bg-green-50 text-green-700 text-sm rounded-md p-3">{{ session('status') }}</div>
            @endif

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8zm6 3c0-1.657-2.686-3-6-3s-6 1.343-6 3" /></svg>
                            {{ __('Users') }}
                        </h3>
                    </div>
                    <div class="flex gap-3">
                        <a href="{{ route('roles.index') }}" class="px-4 py-2 bg-white border border-gray-300 text-gray-700 text-sm font-semibold rounded-md hover:bg-gray-50">
                            Manage Roles
                        </a>
                        <a href="{{ route('users.create') }}" class="px-4 py-2 bg-gray-900 text-white text-sm font-semibold rounded-md hover:bg-gray-700">
                            + New User
                        </a>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-[#eef3fa]">
                            <tr>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Name</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Email</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Phone</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Role</th>
                                <th class="px-5 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($users as $user)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3 text-sm text-gray-900">{{ $user->name }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-500">{{ $user->email }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-500">{{ $user->phone ?: '—' }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-500">
                                        {{ $user->role?->name ?: 'Unrestricted (no role)' }}
                                    </td>
                                    <td class="px-5 py-3 text-sm text-right">
                                        <a href="{{ route('users.edit', $user) }}" class="text-gray-500 hover:text-gray-900 underline decoration-dotted">Edit</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
