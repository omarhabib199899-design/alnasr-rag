@props(['align' => 'left', 'padding' => 'px-5 py-4', 'field' => null])

@php
    $currentSort = request('sort');
    $currentDir = request('direction', 'asc');
    $isActive = $field !== null && $currentSort === $field;
    $nextDir = $isActive && $currentDir === 'asc' ? 'desc' : 'asc';
@endphp

<th {{ $attributes->merge(['class' => $padding.' text-xs font-semibold text-gray-700 uppercase tracking-wide '.($align === 'right' ? 'text-right' : ($align === 'center' ? 'text-center' : 'text-left'))]) }}>
    @if ($field !== null)
        <a href="{{ request()->fullUrlWithQuery(['sort' => $field, 'direction' => $nextDir, 'page' => null]) }}"
           class="inline-flex items-center gap-1 hover:text-gray-900 {{ $align === 'right' ? 'flex-row-reverse' : '' }}">
            {{ $slot }}
            <svg class="w-3 h-3 shrink-0 {{ $isActive ? 'text-blue-600' : 'text-gray-400' }}" viewBox="0 0 20 20" fill="currentColor">
                @if ($isActive && $currentDir === 'asc')
                    <path d="M10 6l4 6H6l4-6z" />
                @elseif ($isActive && $currentDir === 'desc')
                    <path d="M10 14l-4-6h8l-4 6z" />
                @else
                    <path d="M10 3l3.5 4h-7L10 3z" />
                    <path d="M10 17l-3.5-4h7L10 17z" />
                @endif
            </svg>
        </a>
    @else
        <span class="inline-flex items-center gap-1 {{ $align === 'right' ? 'flex-row-reverse' : '' }}">
            {{ $slot }}
        </span>
    @endif
</th>
