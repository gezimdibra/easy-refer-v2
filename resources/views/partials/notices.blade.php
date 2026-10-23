{{--
    Notices region (design-system.md Section 6.6): page-level status
    and error summaries live in one region labelled "Notices".

    Two error modes. The default lists every message, for pages
    whose forms do not mark their fields. Summary mode ($summaryOnly,
    for the F-03 forms: login, register, enrollment, friend claim)
    renders the design's one summary line (Section 3.2 R1 pattern:
    "{n} things to fix:") and nothing else: each problem is stated
    once, at its field, with aria-invalid and aria-describedby
    wiring (Section 4.4 rule 6). Errors keyed in $bannerKeys are
    cross-field problems with no field to mark (the register
    wizard's account errors); those render here, under the summary
    line, per the wizard's banner rule. In summary mode focus moves
    to the summary, which is also the aria-live announcement
    (Section 4.2).
--}}
@php($bannerKeys = $bannerKeys ?? [])
{{-- The status flash is rendered HERE and nowhere else: views in
     layouts that include this partial must not render
     session('status') themselves (the QR and Reviews pages each
     did, and every flash showed twice). One value is not a
     message: 'verification-link-sent' is a machine key the
     verify-email view translates into its own sentence, so it is
     never banner text. --}}
@php($statusMessage = session('status') === 'verification-link-sent' ? null : session('status'))
@if ($statusMessage || $errors->any())
    <div role="region" aria-label="Notices">
        @if ($statusMessage)
            <p class="banner banner-success" role="status">{{ $statusMessage }}</p>
        @endif

        @if ($errors->any())
            @if ($summaryOnly ?? false)
                @php($problemCount = count(array_unique($errors->keys())))
                <div class="banner banner-error" role="alert" tabindex="-1" data-error-summary>
                    <p>{{ $problemCount === 1 ? 'One thing to fix:' : $problemCount.' things to fix:' }}</p>
                    @foreach ($bannerKeys as $bannerKey)
                        @error($bannerKey)
                            <p class="mt-1">{{ $message }}</p>
                        @enderror
                    @endforeach
                </div>
                @once
                    <script>
                        (function () {
                            var el = document.querySelector('[data-error-summary]');
                            if (el) { el.focus(); }
                        })();
                    </script>
                @endonce
            @else
                <div class="banner banner-error" role="alert">
                    <ul class="list-inside list-disc">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endif
    </div>
@endif
