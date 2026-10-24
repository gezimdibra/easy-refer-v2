{{--
    The FAQ accordion (design Section 3.1): a disclosure list, not a
    card stack (CF-06). One container with line-token separators; no
    per-item fill, shadow, or radius. Accordion behavior and aria
    wiring match the pattern the card version established. The
    heading level is a parameter: the FAQ page's questions are h2,
    section-embedded lists use h3. Redesign (redesign-spec.md
    Section 10.1 item 8): the plus/minus text glyph is replaced by
    the chevron-down icon rotating 150ms when open; progressive
    disclosure per the owner's principles list.
--}}
@php($heading = $heading ?? 'h3')
<div class="divide-y divide-line border-y border-line">
    @foreach ($faqs as $faq)
        <div x-data="{ open: false }">
            <{{ $heading }}>
                <button type="button" class="flex min-h-[48px] w-full items-center justify-between gap-4 py-5 text-left" :aria-expanded="open.toString()" @click="open = ! open">
                    <span class="type-title-4 text-text-1">{{ $faq['q'] }}</span>
                    <span class="flex-none text-text-3 transition-transform duration-150" x-bind:class="open ? 'rotate-180' : ''"><x-icon name="chevron-down" :size="20" /></span>
                </button>
            </{{ $heading }}>
            <div x-show="open" x-cloak>
                <p class="type-body pb-5 text-text-2">{{ $faq['a'] }}</p>
            </div>
        </div>
    @endforeach
</div>
