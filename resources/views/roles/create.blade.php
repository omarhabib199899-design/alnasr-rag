<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('New Role') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('roles.store') }}" class="bg-white shadow-sm sm:rounded-lg p-6 space-y-6">
                @csrf

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Role Name</label>
                    <input type="text" name="name" value="{{ old('name') }}" required
                           class="w-full rounded-md border-gray-300 text-sm focus:border-gray-500 focus:ring-gray-500">
                    @error('name')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
                </div>

                @foreach ($groupedPermissions as $group => $permissions)
                    <div>
                        <h3 class="text-sm font-semibold text-gray-700 uppercase mb-2">{{ $group }}</h3>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                            @foreach ($permissions as $key => $label)
                                <label class="flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" name="permissions[]" value="{{ $key }}"
                                           @checked(collect(old('permissions'))->contains($key))
                                           class="rounded border-gray-300 text-gray-800 focus:ring-gray-500">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <div class="flex items-center gap-3">
                    <button type="submit" class="px-4 py-2 bg-gray-800 text-white text-sm font-semibold rounded-md hover:bg-gray-700">
                        Create Role
                    </button>
                    <a href="{{ route('roles.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
