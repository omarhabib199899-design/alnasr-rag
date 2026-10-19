@props([
    'variant' => 'default',
    'size' => 'md',
    'icon' => null,
    'title',
    'description' => null,
    'leading' => null,
    'step' => null,
    'arrow' => false,
])

@php
    $classes = "feature-card feature-card--{$variant} feature-card--{$size}";
@endphp

<article {{ $attributes->merge(['class' => $classes]) }}>
    @if ($step)
        <div class="feature-card__step">{{ $step }}</div>
    @endif

    @if ($icon)
        <div class="feature-card__icon flex-center"><i class="ic-base bg-cover ic-{{ $icon }}"></i></div>
    @elseif ($leading)
        <div class="feature-card__leading">{!! $leading !!}</div>
    @endif

    <div class="feature-card__title">{{ $title }}</div>

    @if ($description)
        <div class="feature-card__description">{{ $description }}</div>
    @endif

    @if ($arrow)
        <div class="feature-card__arrow"><i class="ic-base bg-cover ic-s18 ic-arrow-right"></i></div>
    @endif
</article>
