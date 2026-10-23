{{--
    The FAQ accordion (design Section 3.1): a disclosure list, not a
    card stack (CF-06). One container with line-token separators; no
    per-item fill, shadow, or radius. Accordion behavior and aria
    wiring match the pattern the card version established. The
    heading level is a parameter: the FAQ page's questions are h2,
    section-embedded lists use h3.
--}}
@php($heading = $heading ?? 'h3')
<div class="divide-y divide-line border-y border-line">
    @foreach ($faqs as $faq)
        <div x-data="{ open: false }">
            <{{ $heading }}>
                <button type="button" class="flex w-full items-center justify-between py-4 text-left text-lg font-semibold text-text-1" :aria-expanded="open.toString()" @click="open = ! open">
                    {{ $faq['q'] }}
                    <span aria-hidden="true" x-text="open ? '−' : '+'"></span>
                </button>
            </{{ $heading }}>
            <div x-show="open" x-cloak>
                <p class="pb-5 text-base text-text-2">{{ $faq['a'] }}</p>
            </div>
        </div>
    @endforeach
</div>
