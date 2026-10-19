@props(['crumbs' => [], 'title'])

{{-- Matches the reference portal's page header: 12px breadcrumb with chevrons (current crumb bold), 24/26px semibold title. --}}
<div class="lv-page-header">
    @if (count($crumbs) > 0)
        <nav aria-label="Breadcrumb" class="lv-crumbs">
            @foreach ($crumbs as $crumb)
                @if (! $loop->last)
                    <span>{{ $crumb }}</span>
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                @else
                    <span class="lv-crumb-current">{{ $crumb }}</span>
                @endif
            @endforeach
        </nav>
    @endif
    <h1 class="lv-title">{{ $title }}</h1>
</div>

@once
    <style>
        .lv-page-header{padding-bottom:0}
        .lv-crumbs{display:flex;flex-wrap:wrap;align-items:center;gap:4px;font-size:12px;color:var(--c360-label,#64748B)}
        .lv-crumb-current{font-weight:600;color:var(--c360-heading,#14243B)}
        .lv-title{margin-top:8px;font-size:24px;font-weight:600;line-height:1.25;letter-spacing:-.025em;color:var(--c360-heading,#14243B)}
        @media (min-width:640px){.lv-title{font-size:26px}}
    </style>
@endonce
