{{-- App-wide paginator, matching the reference portal: "Showing X–Y of Z" on the left, Previous / Page X of Y / Next on the right. --}}
@if ($paginator->total() > 0)
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="lv-pager">
        <span>{{ __('Showing') }} {{ number_format($paginator->firstItem()) }}–{{ number_format($paginator->lastItem()) }} {{ __('of') }} {{ number_format($paginator->total()) }}</span>

        @if ($paginator->hasPages())
            <div class="lv-pager-nav">
                @if ($paginator->onFirstPage())
                    <span class="lv-pager-btn" aria-disabled="true">{{ __('Previous') }}</span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="lv-pager-btn">{{ __('Previous') }}</a>
                @endif

                <span>{{ __('Page') }} {{ number_format($paginator->currentPage()) }} {{ __('of') }} {{ number_format($paginator->lastPage()) }}</span>

                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="lv-pager-btn">{{ __('Next') }}</a>
                @else
                    <span class="lv-pager-btn" aria-disabled="true">{{ __('Next') }}</span>
                @endif
            </div>
        @endif
    </nav>
@endif
