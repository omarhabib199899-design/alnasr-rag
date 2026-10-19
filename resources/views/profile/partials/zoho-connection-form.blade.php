<section class="space-y-6">
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Zoho Inventory') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Connect your Zoho Inventory account to sync stock automatically every hour, instead of uploading Excel files manually.') }}
        </p>
    </header>

    @if (session('zoho_status'))
        <p class="text-sm text-gray-600">{{ session('zoho_status') }}</p>
    @endif

    @if (! $zohoConnection)
        <a href="{{ route('zoho.connect') }}"
           class="inline-flex items-center px-4 py-2 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest"
           style="background-color: #2a78d6;">
            {{ __('Connect Zoho Inventory') }}
        </a>
    @else
        <div class="text-sm text-gray-600 space-y-1">
            <p>
                {{ __('Status') }}:
                <span class="font-medium {{ $zohoConnection->status === 'error' ? 'text-red-600' : 'text-green-700' }}">
                    {{ ucfirst($zohoConnection->status) }}
                </span>
            </p>
            <p>
                {{ __('Last synced') }}:
                {{ $zohoConnection->last_synced_at ? $zohoConnection->last_synced_at->diffForHumans() : __('never') }}
            </p>

            @if ($zohoConnection->status === 'error' && $zohoConnection->last_error)
                <p class="text-red-600">{{ $zohoConnection->last_error }}</p>
            @endif
        </div>

        <div class="flex items-center gap-4">
            <form method="post" action="{{ route('zoho.sync-now') }}">
                @csrf
                <x-primary-button>{{ __('Sync Now') }}</x-primary-button>
            </form>

            <form method="post" action="{{ route('zoho.disconnect') }}">
                @csrf
                @method('delete')
                <x-danger-button>{{ __('Disconnect') }}</x-danger-button>
            </form>
        </div>
    @endif
</section>
