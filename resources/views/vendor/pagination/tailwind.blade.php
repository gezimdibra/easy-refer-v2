{{--
    The product's pagination view (DG-8, release 08b), published
    over the framework default. The framework view drew its
    prev/next chevrons as filled glyphs from outside the icon
    system, doubled them (a word plus a glyph that read as a
    second arrow), and stood 38px tall. Here the standalone
    Previous and Next controls are ghost buttons at the 48px
    floor carrying the Lucide chevron from the icon component
    (the previous chevron is the same glyph turned around, so
    the closed icon inventory does not grow), and the page
    numbers stand 48px tall too. The results line and the page
    element structure are the framework's, unchanged.
--}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}">
        <div class="flex items-center justify-between gap-2 sm:hidden">
            @if ($paginator->onFirstPage())
                <span class="btn btn-ghost cursor-not-allowed opacity-50" aria-disabled="true"><x-icon name="chevron-right" :size="20" style="transform: rotate(180deg)" /> Previous</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="btn btn-ghost" aria-label="{{ __('pagination.previous') }}"><x-icon name="chevron-right" :size="20" style="transform: rotate(180deg)" /> Previous</a>
            @endif

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="btn btn-ghost" aria-label="{{ __('pagination.next') }}">Next <x-icon name="chevron-right" :size="20" /></a>
            @else
                <span class="btn btn-ghost cursor-not-allowed opacity-50" aria-disabled="true">Next <x-icon name="chevron-right" :size="20" /></span>
            @endif
        </div>

        <div class="hidden sm:flex sm:items-center sm:justify-between sm:gap-2">
            <div>
                <p class="text-sm leading-5 text-text-2">
                    {!! __('Showing') !!}
                    @if ($paginator->firstItem())
                        <span class="font-medium">{{ $paginator->firstItem() }}</span>
                        {!! __('to') !!}
                        <span class="font-medium">{{ $paginator->lastItem() }}</span>
                    @else
                        {{ $paginator->count() }}
                    @endif
                    {!! __('of') !!}
                    <span class="font-medium">{{ $paginator->total() }}</span>
                    {!! __('results') !!}
                </p>
            </div>

            <div class="flex items-center gap-1">
                {{-- Previous Page Link --}}
                @if ($paginator->onFirstPage())
                    <span class="btn btn-ghost cursor-not-allowed opacity-50" aria-disabled="true" aria-label="{{ __('pagination.previous') }}"><x-icon name="chevron-right" :size="20" style="transform: rotate(180deg)" /> Previous</span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="btn btn-ghost" aria-label="{{ __('pagination.previous') }}"><x-icon name="chevron-right" :size="20" style="transform: rotate(180deg)" /> Previous</a>
                @endif

                {{-- Pagination Elements --}}
                @foreach ($elements as $element)
                    {{-- "Three Dots" Separator --}}
                    @if (is_string($element))
                        <span class="inline-flex min-h-12 min-w-12 items-center justify-center text-text-2" aria-disabled="true">{{ $element }}</span>
                    @endif

                    {{-- Array Of Links --}}
                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span class="inline-flex min-h-12 min-w-12 items-center justify-center rounded-md bg-surface-2 px-3 text-[15px] font-semibold text-text-1" aria-current="page">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" class="inline-flex min-h-12 min-w-12 items-center justify-center rounded-md px-3 text-[15px] font-medium text-text-2 hover:bg-surface-1 hover:text-text-1" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">{{ $page }}</a>
                            @endif
                        @endforeach
                    @endif
                @endforeach

                {{-- Next Page Link --}}
                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="btn btn-ghost" aria-label="{{ __('pagination.next') }}">Next <x-icon name="chevron-right" :size="20" /></a>
                @else
                    <span class="btn btn-ghost cursor-not-allowed opacity-50" aria-disabled="true" aria-label="{{ __('pagination.next') }}">Next <x-icon name="chevron-right" :size="20" /></span>
                @endif
            </div>
        </div>
    </nav>
@endif
