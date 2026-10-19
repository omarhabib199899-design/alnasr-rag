@php
    // Simple in-app notification bell — Laravel's built-in database
    // notification channel (Auth::user()->notifications), no email/SMS
    // involved. Queried inline here rather than via a view composer since
    // this partial is the only place it's used.
    $unreadNotifications = Auth::user()->unreadNotifications()->latest()->take(20)->get();
    $unreadCount = $unreadNotifications->count();
@endphp

<div class="relative ml-auto" x-data="{ open: false }">
    <button @click="open = ! open" class="relative text-gray-500 hover:text-gray-700">
        <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
        </svg>
        @if ($unreadCount > 0)
            <span class="absolute -top-1 -right-1 inline-flex items-center justify-center h-4 min-w-[1rem] px-1 rounded-full bg-red-600 text-white text-[10px] font-bold">
                {{ $unreadCount > 9 ? '9+' : $unreadCount }}
            </span>
        @endif
    </button>

    <div x-show="open" @click.outside="open = false" x-cloak
         class="absolute right-0 mt-2 w-96 max-w-[90vw] bg-white rounded-md shadow-lg border border-gray-200 z-50">
        <div class="px-4 py-2 border-b border-gray-100 flex items-center justify-between">
            <span class="text-sm font-semibold text-gray-800">Notifications</span>
            @if ($unreadCount > 0)
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <button type="submit" class="text-xs text-blue-600 hover:underline">Mark all read</button>
                </form>
            @endif
        </div>
        <div class="max-h-96 overflow-y-auto divide-y divide-gray-100">
            @forelse ($unreadNotifications as $notification)
                <a href="{{ route('notifications.open', $notification->id) }}" class="block px-4 py-3 hover:bg-gray-50">
                    <div class="text-sm font-medium text-gray-900">{{ $notification->data['title'] ?? 'Notification' }}</div>
                    <div class="text-xs text-gray-500 mt-0.5">{{ $notification->data['body'] ?? '' }}</div>
                    <div class="text-xs text-gray-400 mt-1">{{ $notification->created_at->diffForHumans() }}</div>
                </a>
            @empty
                <div class="px-4 py-6 text-center text-sm text-gray-500">No new notifications.</div>
            @endforelse
        </div>
    </div>
</div>
