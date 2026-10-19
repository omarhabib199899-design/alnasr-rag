@props([
    'variant' => 'default',
    'size' => 'md',
    'icon' => null,
    'title',
    'badge' => null,
    'description' => null,
    'leading' => null,
    'arrow' => false,
    'href' => null,
])

@php
    $classes = "info-card info-card--{$variant} info-card--{$size}";
@endphp

@php $tag = $href ? 'a' : 'article'; @endphp
<{{ $tag }} {{ $href ? 'href="'.$href.'"' : '' }} {{ $attributes->merge(['class' => $classes]) }}>
    @if ($icon)
        <div class="info-card__icon flex-center"><i class="ic-base bg-cover ic-{{ $icon }}"></i></div>
    @elseif ($leading)
        <div class="info-card__leading flex-center">{{ $leading }}</div>
    @endif

    <div class="info-card__content flex flex-col">
        @if ($badge)
            <span class="info-card__badge">{{ $badge }}</span>
        @endif
        <h3 class="info-card__title">{{ $title }}</h3>
        @if ($description)
            <p class="info-card__description">{{ $description }}</p>
        @endif
    </div>

    @if ($arrow)
        <i class="ic-base ic-s18 ic-arrow-right info-card__arrow"></i>
    @endif
</{{ $tag }}>
