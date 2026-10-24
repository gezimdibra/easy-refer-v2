{{--
    The FAQ list (design Section 3.1): a disclosure list, not a
    card stack (CF-06). One container with line-token separators; no
    per-item fill, shadow, or radius. The heading level is a
    parameter: the FAQ page's questions are h2, section-embedded
    lists use h3/h4. Redesign (redesign-spec.md Section 10.1 item
    8): the plus/minus text glyph is replaced by the chevron-down
    icon rotating 150ms when open; progressive disclosure per the
    owner's principles list.

    Release 08b (WS-4, ruling 5): one shared Alpine scope for the
    whole list, so at most one answer is open at a time on every
    surface that renders this component (previously each item
    carried its own scope and any number could stand open, which
    is what let the /faq prose window run to 74.1%). Questions
    carry the heading step via the .faq-q class (TX-D1: 17px, 600,
    24px, in app.css), visibly senior to the body answers at every
    width. Answers start cloaked; the [x-cloak] rule ships in
    app.css (P-03).

    Release 08d (ruling 5 conformance): the presentation is a mode.
    'accordion' (the default) is the single-open disclosure above,
    for section-embedded lists (home, verticals) where space is
    contested. 'flat' renders every answer in the open, no script
    involved: the /faq page is a reference surface whose readers
    arrive with one question and are served by visible, findable
    answers (merge ruling 5 kept the page flat; the 08b accordion
    leaked onto it through this shared partial, and the 08c mobile
    re-test + designer gate both flagged the deviation). The .faq-q
    step and the separators are identical in both modes.
--}}
@php($heading = $heading ?? 'h3')
@php($mode = $mode ?? 'accordion')
@if ($mode === 'flat')
    <div class="divide-y divide-line border-y border-line">
        @foreach ($faqs as $faq)
            <div class="py-5">
                <{{ $heading }}><span class="faq-q text-text-1">{{ $faq['q'] }}</span></{{ $heading }}>
                <p class="type-body mt-2 text-text-2">{{ $faq['a'] }}</p>
            </div>
        @endforeach
    </div>
@else
    <div class="divide-y divide-line border-y border-line" x-data="{ openItem: null }">
        @foreach ($faqs as $i => $faq)
            <div>
                <{{ $heading }}>
                    <button type="button" class="flex min-h-[48px] w-full items-center justify-between gap-4 py-5 text-left" :aria-expanded="(openItem === {{ $i }}).toString()" @click="openItem = openItem === {{ $i }} ? null : {{ $i }}">
                        <span class="faq-q text-text-1">{{ $faq['q'] }}</span>
                        <span class="flex-none text-text-3 transition-transform duration-150" x-bind:class="openItem === {{ $i }} ? 'rotate-180' : ''"><x-icon name="chevron-down" :size="20" /></span>
                    </button>
                </{{ $heading }}>
                <div x-show="openItem === {{ $i }}" x-cloak>
                    <p class="type-body pb-5 text-text-2">{{ $faq['a'] }}</p>
                </div>
            </div>
        @endforeach
    </div>
@endif
