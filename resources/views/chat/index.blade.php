<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-base text-gray-900 leading-tight">
            {{ __('Ask about your inventory') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-5">

            <x-page-header :crumbs="['Ask about your inventory']" title="Ask about your inventory" />

            <div class="bg-white rounded-lg border border-gray-200 p-6 space-y-5 min-h-[300px]">
                <div id="chat-messages" class="space-y-5">
                    @forelse ($messages as $message)
                        @include('partials.chat-message', ['message' => $message])
                    @empty
                    @endforelse
                </div>
                <p id="chat-empty" class="text-sm text-gray-400 text-center py-12" @if ($messages->isNotEmpty()) style="display:none" @endif>
                    Ask something like "how many units of SKU-001 are in stock" or "how can I increase sales next month".
                </p>
                <div id="chat-thinking" class="flex justify-start" style="display:none">
                    <div class="bg-gray-100 text-gray-500 rounded-lg px-4 py-2 text-sm italic">Thinking…</div>
                </div>
            </div>

            <form id="chat-form" method="POST" action="{{ route('chat.send') }}" class="flex gap-2">
                @csrf
                <input type="text" id="chat-question" name="question" required maxlength="1000" autofocus
                       placeholder="Ask a question about your inventory..."
                       class="flex-1 border-gray-300 rounded-md shadow-sm">
                <x-primary-button id="chat-submit">{{ __('Send') }}</x-primary-button>
            </form>
        </div>
    </div>

    <script>
        document.getElementById('chat-form').addEventListener('submit', function (e) {
            e.preventDefault();

            const form = e.target;
            const input = document.getElementById('chat-question');
            const submitBtn = document.getElementById('chat-submit');
            const question = input.value.trim();
            if (!question) return;

            const messagesEl = document.getElementById('chat-messages');
            document.getElementById('chat-empty').style.display = 'none';
            input.value = '';
            input.disabled = true;
            submitBtn.disabled = true;
            document.getElementById('chat-thinking').style.display = 'flex';

            const body = new FormData();
            body.append('question', question);

            fetch(form.action, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body,
            })
                .then(response => {
                    if (!response.ok) throw new Error('Request failed');
                    return response.json();
                })
                .then(data => {
                    messagesEl.insertAdjacentHTML('beforeend', data.user_html);
                    messagesEl.insertAdjacentHTML('beforeend', data.assistant_html);
                })
                .catch(() => {
                    // Fall back to a normal form submission if the AJAX path fails for any reason.
                    const fallback = document.createElement('form');
                    fallback.method = 'POST';
                    fallback.action = form.action;
                    fallback.innerHTML = form.innerHTML;
                    fallback.querySelector('#chat-question').value = question;
                    document.body.appendChild(fallback);
                    fallback.submit();
                })
                .finally(() => {
                    document.getElementById('chat-thinking').style.display = 'none';
                    input.disabled = false;
                    submitBtn.disabled = false;
                    input.focus();
                });
        });
    </script>
</x-app-layout>
