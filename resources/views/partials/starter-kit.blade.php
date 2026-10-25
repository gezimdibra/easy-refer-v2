{{--
    The starter kit lead form (inventory LEAD-01, spec Section 2.7):
    a section on Home and the closing block of each vertical page.
    There is no standalone starter-kit route. Redesign
    (redesign-spec.md Section 10.1 item 10): two columns, text left
    and the form in a radius-xl card at 32px padding, with the
    email input and submit joined on one outline.
    Copy release (2026-10-24, Amendment 11): the body line takes
    the slim-down wording and the PROSE-GAP mt-4 floor. On home
    the kit no longer stands as its own section (O-3): home
    passes $band and the kit renders inside the closing band, so
    the page ends on one ask cluster, not two adjacent asks. The
    #starter-kit id survives in both modes (the sticky bar's
    suppression zone watches it). The form itself lives in
    partials/starter-kit-form so it exists exactly once.
--}}
{{-- 08f (contrast audit F-10): the section ground is a
     parameter so each page's rhythm assignment can place the
     kit on the ground its neighbours are not using: the
     verticals pass the cream ground. (Home no longer uses the
     standalone section; see the header note.) --}}
@if ($band ?? false)
    <div id="starter-kit" class="mx-auto mt-16 max-w-[720px] border-t border-on-dark-line pt-16 text-center">
        <h2 class="type-title-2 text-white">Get the free Referral Program Starter Kit</h2>
        <p class="type-body mx-auto mt-4 max-w-[68ch] text-dark-muted">We email you the kit and a few short tips. Unsubscribe any time.</p>
        <div class="mx-auto mt-8 max-w-[560px] rounded-xl border border-line bg-surface-0 text-left shadow-e1" style="padding: 32px">
            @include('partials.starter-kit-form', ['source' => $source ?? 'home'])
        </div>
    </div>
@else
    <section id="starter-kit" class="{{ $ground ?? 'bg-surface-0' }}">
        <div class="shell section-pad grid items-start gap-10 lg:grid-cols-2">
            <div>
                <h2 class="type-title-2 text-text-1">Get the free Referral Program Starter Kit</h2>
                <p class="type-body mt-4 max-w-[68ch] text-text-2">We email you the kit and a few short tips. Unsubscribe any time.</p>
            </div>
            <div class="rounded-xl border border-line bg-surface-0 shadow-e1" style="padding: 32px">
                @include('partials.starter-kit-form', ['source' => $source ?? 'home'])
            </div>
        </div>
    </section>
@endif
