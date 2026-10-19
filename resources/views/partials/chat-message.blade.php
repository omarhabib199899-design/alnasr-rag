@if ($message->role === 'user')
    <div class="flex justify-end">
        <div class="bg-gray-800 text-white rounded-lg px-4 py-2 max-w-lg whitespace-pre-wrap text-sm">
            {{ $message->content }}
        </div>
    </div>
@else
    <div class="flex justify-start">
        <div class="max-w-lg">
            @if ($message->answer_type === 'computed')
                <span class="inline-block mb-1 px-2 py-0.5 rounded text-xs font-semibold bg-green-100 text-green-800">Computed</span>
            @elseif ($message->answer_type === 'advisory')
                <span class="inline-block mb-1 px-2 py-0.5 rounded text-xs font-semibold bg-amber-100 text-amber-800">Suggestion — not a computed fact</span>
            @else
                <span class="inline-block mb-1 px-2 py-0.5 rounded text-xs font-semibold bg-gray-100 text-gray-600">Note</span>
            @endif
            <div class="bg-gray-100 text-gray-900 rounded-lg px-4 py-2 whitespace-pre-wrap text-sm">
                {{ $message->content }}
            </div>
            @if ($message->answer_type === 'computed' && $message->computed_spec)
                <form method="POST" action="{{ route('widgets.store') }}" class="mt-1">
                    @csrf
                    <input type="hidden" name="chat_message_id" value="{{ $message->id }}">
                    <button type="submit" class="text-xs text-indigo-600 hover:underline">Pin to dashboard</button>
                </form>
            @endif
        </div>
    </div>
@endif
