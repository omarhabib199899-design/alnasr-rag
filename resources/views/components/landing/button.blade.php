@props([
    'variant' => 'primary',
    'size' => 'md',
    'radius' => 'md',
    'rounded' => false,
    'href' => null,
    'type' => 'button',
    'leftIcon' => null,
    'rightIcon' => null,
])

@php
    $classes = collect([
        'app-btn',
        "app-btn--{$variant}",
        "app-btn--{$size}",
        "app-btn--radius-{$radius}",
        $rounded ? 'app-btn--rounded' : null,
    ])->filter()->implode(' ');
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($leftIcon)
            <span class="app-btn__icon flex-center app-btn__icon--left"><i class="ic-base ic-{{ $leftIcon }}"></i></span>
        @endif
        <span class="app-btn__content flex-center">{{ $slot }}</span>
        @if ($rightIcon)
            <span class="app-btn__icon app-btn__icon--right"><i class="ic-base ic-{{ $rightIcon }}"></i></span>
        @endif
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($leftIcon)
            <span class="app-btn__icon flex-center app-btn__icon--left"><i class="ic-base ic-{{ $leftIcon }}"></i></span>
        @endif
        <span class="app-btn__content flex-center">{{ $slot }}</span>
        @if ($rightIcon)
            <span class="app-btn__icon app-btn__icon--right"><i class="ic-base ic-{{ $rightIcon }}"></i></span>
        @endif
    </button>
@endif
