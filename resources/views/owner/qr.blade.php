@extends('layouts.app')

@section('title', 'Your QR code')

@section('content')
    {{-- The QR page (spec Section 5.1, design 3.4, DASH-08;
         redesign-spec.md Section 10.5 item 4): the code in a
         framed, centered composition, the designed poster set
         with previews and its use labels, the placements panel
         (QRK-07), how redemption works, and the danger zone
         with its consequence modal. The public link renders as a designed
         copy field under the code, not a naked debug string
         (visual audit Q-1). --}}
    <h1 class="type-title-2 text-text-1">Your QR code</h1>
    <p class="type-body mt-2 text-text-2">Customers scan this code to join and start referring.</p>

    @include('partials.coupon-tabs', ['active' => 'referral'])

    {{-- G1 (coherence Phase 2): while the flow is inactive,
         the page that distributes the code says so before the
         owner prints — the notice names the missing step. --}}
    @unless ($business->flowIsActive())
        <div class="gate mt-6">
            @include('partials.qr-inactive-notice')
        </div>
    @endunless

    {{-- ACT-VIS-02 (activation redesign, Section 6.2): at
         exactly 1 free visit left with no card, the warning
         stands on the page where the owner prints — before
         the last slot goes, not at zero. --}}
    @if ($business->flowIsActive() && ! $business->hasCardOnFile() && $business->allowanceLeft() === 1)
        <div class="gate mt-6">
            <p>One free visit left. After it, your code pauses until you add a card. Visits you have already earned still finish as usual.</p>
            <p class="mt-2"><a class="text-action" href="{{ route('settings.billing') }}#card">Add a card</a></p>
        </div>
    @endif

    {{-- MIX-1 (poster mix, Amendment 27): Start here replaces
         the hero code card. The recommendation is the counter
         poster: a preview rendered from the real poster view
         (preview mode, scaled live sheet render of the
         business's own counter poster, the train's one new
         rendering decision, recorded in the lane report), three
         plain sentences, and the section's ONE primary button.
         The hero's share tools move to the quiet share strip
         below, unchanged. --}}
    <div class="panel mt-6" id="start-here">
        <h2 class="type-title-3 text-text-1">Start here: your first poster</h2>
        <div class="mt-4 flex flex-col gap-4 sm:flex-row sm:items-center">
            <div class="poster-preview flex-none overflow-hidden rounded-lg border border-line" style="width: 190px; height: 269px" aria-hidden="true">
                <iframe src="{{ route('owner.qr.poster', ['variant' => 'counter', 'preview' => 1]) }}" tabindex="-1" loading="lazy" title="" style="width: 559px; height: 791px; border: 0; transform: scale(0.34); transform-origin: top left; pointer-events: none"></iframe>
            </div>
            <div class="min-w-0 flex-1">
                <p class="type-body font-semibold text-text-1">The counter poster</p>
                <p class="type-body mt-2 text-text-2">It stands by your register, where customers see it while they pay. It prints on half a sheet of paper (5.8 x 8.3 in). Once it is up, it keeps working on its own.</p>
                <p class="mt-4">
                    <a class="btn btn-primary" href="{{ route('owner.qr.poster', ['variant' => 'counter', 'print' => 1]) }}"><x-icon name="printer" :size="20" /> Print this poster</a>
                </p>
                @unless ($business->flowIsActive())
                    @include('partials.print-inactive-warning')
                @endunless
            </div>
        </div>
        {{-- RC-2 Variant 2 (Amendment 41): the pair. The receipt
             card is the named second step inside Start here, with
             the staff one-line script. OR-1 §1.3 A7 re-key
             (Amendment 49): the gate is now REWARD SET (any
             configured state) — the old both-texts gate withheld
             the panel from exactly the default UNIFIED
             population, whose referrer is promised the same
             reward by definition. The script sentence needs no
             branch: "you both get a reward" asserts each side
             gets A reward, true in UNIFIED and SPLIT alike, and
             the block is absent in UNSET (the gate). The panel's
             W1 print warning above covers this block's print
             button too (same panel, read before it). --}}
        @if ($business->hasFriendRewardText())
            <div class="mt-4 border-t border-line" style="padding-top: 16px" id="start-here-pair">
                <p class="type-body font-semibold text-text-1">Then hand out the receipt card</p>
                <p class="type-body mt-2 text-text-2">Print a stack of small cards and hand one out with every receipt. The card reaches every paying customer, including the ones who never look up at a poster, and it needs no wall space.</p>
                <p class="type-body mt-2 text-text-2">When your staff hand one out, one line is enough: This one is for a friend. If they come in, you both get a reward.</p>
                <p class="mt-4">
                    <a class="btn btn-secondary" href="{{ route('owner.qr.poster', ['variant' => 'receipt', 'print' => 1]) }}"><x-icon name="printer" :size="20" /> Print the receipt card</a>
                </p>
            </div>
        @endif
    </div>

    {{-- MIX-1 share strip (Variant A's quiet strip, verbatim):
         the public link as a designed copy field and the
         code-only download, at strip volume under Start here. --}}
    <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
        <div class="min-w-0 flex-1">
            <p class="type-body font-semibold text-text-1">Share your code online</p>
            <p class="type-body mt-2 text-text-2">Your sign-up link, to paste anywhere. Or download just the code image, for your own designs.</p>
        </div>
        <div class="flex flex-col gap-3">
            <div class="joined" x-data="{ copied: false }">
                <input type="text" readonly value="{{ $publicUrl }}" class="form-input form-input-app" aria-label="Your public referral link" x-ref="publicUrl">
                <button type="button" class="btn btn-secondary"
                    @click="navigator.clipboard.writeText($refs.publicUrl.value); copied = true"
                    x-text="copied ? 'Copied' : 'Copy link'">Copy link</button>
            </div>
            <form method="POST" action="{{ route('owner.qr.download') }}" class="text-center" data-loading>
                @csrf
                <button type="submit" class="btn btn-secondary"><x-icon name="download" :size="20" /> Download code only (for your own designs)</button>
            </form>
        </div>
    </div>

    {{-- ID-04 placement coaching (Variant B, Amendment 47
         [COACH-01..05]): one comparison card, rendered only
         when the full floor holds (the controller passes null
         below the floor, so no shell, teaser, or empty state
         exists). One comparison sentence with both counts over
         the same 28 days, one try, the move's own print action,
         and a quiet dismiss. --}}
    @if ($coaching)
        <div class="panel mt-6" id="placement-coaching">
            <h2 class="type-title-3 text-text-1">One spot is bringing in more scans</h2>
            <p class="type-body mt-2 text-text-2">{{ $coaching['body'] }}</p>
            <div class="mt-4 flex flex-wrap items-center gap-2">
                <a class="btn btn-primary" href="{{ $coaching['actionUrl'] }}"><x-icon name="printer" :size="20" /> {{ $coaching['action_label'] }}</a>
                <form method="POST" action="{{ route('owner.qr.coaching.dismiss') }}" data-loading>
                    @csrf
                    <button type="submit" class="text-action type-body">Not now</button>
                </form>
            </div>
        </div>
    @endif

    {{-- MIX-2 (poster mix, Amendment 27): the by-spot panel
         replaces the placements table. One row per spot from
         the same placements derivation (QRK-07), minus the
         Added date (S4: stats live in the rows only, in the
         fixed "{n} scans · {n} joins so far" format); each
         row's print URL is the existing per-placement poster
         route and names its artifact. The FE-LBL-01 footnote
         slot survives under the panel as "Totals are all
         time." (Amendment 25), inside the M-4 word budget. --}}
    @php
        // [MIX-04] (Amendment 31, superseding the [MIX-03] spot
        // lines): where it goes AND its real print size, from
        // the poster templates' @page sizes.
        $spotLines = [
            'counter' => 'By the register, where people pay. Prints on half a sheet of paper (5.8 x 8.3 in).',
            'window' => 'In the front window, facing out. A full sheet of paper (8.3 x 11.7 in), so people can read it from the sidewalk.',
            'table' => 'Standing up on your tables. The sheet is 8.3 x 5.8 in and folds in half to stand.',
            'receipt' => 'Handed out with the receipt. A small card, 3.3 x 2.2 in, the size of a business card.',
        ];
        $printLabels = [
            'counter' => 'Print the counter poster',
            'window' => 'Print the window poster',
            'table' => 'Print the table tent',
            'receipt' => 'Print the receipt card',
        ];
    @endphp
    <div class="panel mt-6" id="by-spot">
        <h2 class="type-title-3 text-text-1">Print by spot</h2>
        {{-- [ON-SPOT-1] (Amendment 31): counting is per code,
             and the name is only a label. --}}
        <p class="type-body mt-2 text-text-2">Every spot gets its own code, so you can see which spot brings people in. Scans are counted per code: the numbers under a code come from that printed code only. The name is only a label. Rename any code to match where you really put it.</p>
        @unless ($business->flowIsActive())
            @include('partials.print-inactive-warning')
        @endunless
        <ul>
            @foreach ($placements as $row)
                <li data-placement="{{ $row['key'] }}" class="border-line {{ $loop->first ? '' : 'border-t' }}" style="padding: 14px 0">
                    <div class="flex items-start gap-3">
                        <span class="flex h-14 w-14 flex-none items-center justify-center rounded-lg border border-line bg-surface-0 p-1.5">
                            <img src="{{ route('owner.qr.image.placement', ['placement' => $row['key']]) }}" alt="" class="h-full w-full">
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="type-body block font-semibold text-text-1">{{ $row['name'] }}</span>
                            <span class="type-body block text-text-2">{{ $spotLines[$row['key']] }}</span>
                            {{-- QF-3 (gap pack 03, Amendment 29 [QF-1]):
                                 a spot with no scans and no joins yet
                                 reads "No scans yet"; the numeric form
                                 renders only once a count is nonzero. --}}
                            @if ($row['scans'] === 0 && $row['joins'] === 0)
                                <span class="type-body block text-text-2">No scans yet</span>
                            @else
                                <span class="type-body block text-text-2">{{ $row['scans'] }} scans · {{ $row['joins'] }} joins so far</span>
                            @endif
                            {{-- SA-06 (Amendment 41): the spot's
                                 age and last scan, on the row, so
                                 day 1 and day 90 never read the
                                 same. Dates are business-local
                                 (controller). --}}
                            <span class="type-body block text-text-2">Added {{ $row['added'] }}@if ($row['lastScan']) · Last scan {{ $row['lastScan'] }}@endif</span>
                            {{-- SA-06 dead-spot nudge: plain text
                                 on the row only, no banner, no
                                 alarm; with only four placements
                                 the page stays calm however many
                                 spots are silent. The receipt row
                                 names the hand-out possibility
                                 (cards may never have been handed
                                 out), never a poster fault. --}}
                            @if ($row['stale'])
                                <span class="type-body block text-text-2" data-dead-spot-nudge>
                                    @if ($row['key'] === 'receipt')
                                        No scans in over a month.@if ($row['scans'] === 0) This code has been out since {{ $row['added'] }}.@endif Check the cards are by the register and one goes out with every receipt.
                                    @elseif ($row['scans'] === 0)
                                        No scans in over a month. This code has been out since {{ $row['added'] }}. Check the poster is up where customers can see it.
                                    @else
                                        No scans in over a month. Check the poster is still up where customers can see it.
                                    @endif
                                </span>
                            @endif
                        </span>
                    </div>
                    {{-- [ON-SPOT-3] (Amendment 31): Rename opens
                         an inline form in the row. The form starts
                         open when this row's rename error bag
                         carries an error, so a refused name shows
                         its error at the field, never hidden. --}}
                    @php($renameErrors = $errors->getBag('rename_'.$row['key']))
                    <div x-data="{ renaming: {{ $renameErrors->has('name') ? 'true' : 'false' }} }">
                        <div class="mt-2.5 flex flex-wrap items-center gap-2">
                            <a class="btn btn-secondary" href="{{ route('owner.qr.poster', ['variant' => $row['posterVariant'], 'print' => 1]) }}"><x-icon name="printer" :size="20" /> {{ $printLabels[$row['key']] }}</a>
                            @if ($row['key'] === 'receipt')
                                {{-- RC-3 Candidate F (Amendment 41): the multi-up sheet, the stack form of the same card. --}}
                                <a class="btn btn-secondary" href="{{ route('owner.qr.poster', ['variant' => 'receipt', 'print' => 1, 'multi' => 1]) }}"><x-icon name="printer" :size="20" /> Print a sheet of cards</a>
                            @endif
                            <button type="button" class="type-body inline-flex min-h-11 items-center px-2 text-text-2 underline underline-offset-4" @click="renaming = true">Rename</button>
                            {{-- MIX-3: Replace demoted to a text
                                 link. The trigger stays a real
                                 <button> (pin M-2) with a 44px
                                 target (pin M-5). [ON-SPOT-3]: the
                                 modal title and body read the
                                 code's current display name. --}}
                            <x-confirm-modal
                                :action="route('owner.qr.placement.regenerate', ['placement' => $row['key']])"
                                id="replace-{{ $row['key'] }}"
                                title="Replace this code?"
                                confirm-label="Replace code"
                                confirm-class="btn-danger">
                                <x-slot:trigger><button type="button" class="type-body inline-flex min-h-11 items-center px-2 text-text-2 underline underline-offset-4">Replace code</button></x-slot:trigger>
                                <p>Printed copies of the {{ $row['name'] }} code will stop working right away. Your other codes keep working.</p>
                            </x-confirm-modal>
                        </div>
                        <form method="POST" action="{{ route('owner.qr.placement.rename', ['placement' => $row['key']]) }}" x-show="renaming" x-cloak class="mt-2" data-loading>
                            @csrf
                            <label class="type-body block font-semibold text-text-1" for="name-{{ $row['key'] }}">Name for this code</label>
                            <input type="text" name="name" id="name-{{ $row['key'] }}" class="form-input form-input-app mt-2"
                                value="{{ $renameErrors->has('name') ? old('name') : $row['code']->label }}"
                                aria-invalid="{{ $renameErrors->has('name') ? 'true' : 'false' }}"
                                @if ($renameErrors->has('name')) aria-describedby="name-{{ $row['key'] }}-error name-{{ $row['key'] }}-help" @else aria-describedby="name-{{ $row['key'] }}-help" @endif>
                            <p class="type-body mt-2 text-text-2" id="name-{{ $row['key'] }}-help">The name shows on this page only. Your posters do not change. Leave it empty and save to use the standard name, {{ $row['defaultName'] }}.</p>
                            @if ($renameErrors->has('name'))
                                <p class="form-error" id="name-{{ $row['key'] }}-error">{{ $renameErrors->first('name') }}</p>
                            @endif
                            <div class="mt-2.5 flex flex-wrap items-center gap-2">
                                <button type="submit" class="btn btn-secondary">Save name</button>
                                <button type="button" class="btn btn-secondary" @click="renaming = false">Cancel</button>
                            </div>
                        </form>
                    </div>
                </li>
            @endforeach
        </ul>
        <p class="type-body mt-3 text-text-2">Totals are all time.</p>
    </div>

    {{-- ID-11 benefit hint (Amendment 53, HINT-COUP-01): the
         ONLY hint on this surface, directly under the codes
         area, per the IA ruling's first application of the
         benefit-hint pattern. It shows until the owner creates
         a first batch or dismisses it, then never again; the
         controller passes the boolean, the view only renders.
         Tapping it lands on the Coupons tab with the creation
         form prefilled. --}}
    @if ($couponHint ?? false)
        <div class="panel mt-6" id="coupon-hint">
            <p class="type-body text-text-1"><a class="font-semibold text-link hover:underline" href="{{ route('owner.qr.coupons', ['from' => 'hint']) }}#new-batch">Try Coupons: put your offer on paper around town and bring new people to your door.</a></p>
            <form method="POST" action="{{ route('owner.qr.coupons.hint.dismiss') }}" class="mt-2" data-loading>
                @csrf
                <button type="submit" class="type-body inline-flex min-h-11 items-center px-2 text-text-2 underline underline-offset-4">Dismiss</button>
            </form>
        </div>
    @endif

    {{-- MIX-4 (poster mix, Amendment 27): the mini-poster is a
         format, not a spot, so it sits below the by-spot panel
         as a bare text row (B's placement, C's volume).
         Amendment 31 [ON-QR-1]: the Screen poster row is
         retired (every remaining option is printed paper or
         card) and [MIX-04] gives the mini-poster its size. --}}
    <div class="mt-6" id="also-available">
        <p class="type-body font-semibold text-text-1">Also available</p>
        <ul class="mt-2">
            <li class="flex items-start gap-3" style="padding: 8px 0">
                <span class="type-body min-w-0 flex-1"><span class="font-semibold text-text-1">Mini-poster</span> <span class="text-text-2">A smaller counter poster, for tight spaces. Prints at 4.1 x 5.8 in.</span></span>
                <a class="type-body inline-flex min-h-11 flex-none items-center text-text-1 underline underline-offset-4" href="{{ route('owner.qr.poster', ['variant' => 'mini', 'print' => 1]) }}">Print</a>
            </li>
        </ul>
        @unless ($business->flowIsActive())
            @include('partials.print-inactive-warning')
        @endunless
    </div>

    <div class="panel mt-4" id="how-redemption-works">
        <h2 class="type-title-3 text-text-1">How redemption works</h2>
        {{-- QF-5 (gap pack 03, Amendment 29 [QF-2]): the shipped
             paragraph as a numbered three-step list; the words
             are [QF-2] verbatim and no information from the
             paragraph is lost (shows code; staff scans with
             their own phone; tap Mark as Redeemed; the closing
             no-app line rides under the list). --}}
        <ol class="type-body mt-2 space-y-1 text-text-2">
            <li><span class="font-semibold text-text-1">1.</span> A customer shows their reward code.</li>
            <li><span class="font-semibold text-text-1">2.</span> A staff member scans it with their own phone.</li>
            <li><span class="font-semibold text-text-1">3.</span> They tap Mark as Redeemed.</li>
        </ol>
        <p class="type-body mt-2 text-text-2">No app, no login, nothing to type.</p>
    </div>

    {{-- QF-4 (gap pack 03 section 3 ruling: a truth strip, NOT
         an editor; Amendment 29 [QF-1]): what the poster says,
         shown on the page that prints it. The friend reward
         text exactly as stored (the poster prints it verbatim
         as its headline after "Get"; QrFrameTest asserts the
         two match character for character), the referrer text
         beneath when set, and one secondary action to the one
         write path in Settings Rewards. The unset state is
         [QF-1]'s empty-state line with the same link. --}}
    <div class="panel mt-4" id="poster-says">
        <h2 class="type-title-3 text-text-1">What your poster says</h2>
        @if ($business->friend_reward_text)
            <p class="type-body mt-2 text-text-1" data-poster-friend-text>{{ $business->friend_reward_text }}</p>
            @if ($business->rewardsAreSplit())
                {{-- C-2 (two-key review): the SPLIT branch reads
                     the RESOLVER, not the stored column. In the
                     non-canonical SPLIT + blank-referrer state
                     the resolver falls back to the friend text,
                     which is what the poster promises the
                     referrer, so the panel shows it too. --}}
                @if ($business->effectiveReferrerRewardText())
                    <p class="type-body mt-1 text-text-2" data-poster-referrer-text>{{ $business->effectiveReferrerRewardText() }}</p>
                @endif
            @else
                {{-- ORS-06 (OR-1 §1.3 A6): in UNIFIED the panel's
                     one text is the complete truth, plus the
                     sameness line — the poster in UNIFIED
                     carries the unified give/get line and the
                     YOUR REWARD card with the unified value. --}}
                <p class="type-body mt-1 text-text-2">The same reward goes to the customer who sent them.</p>
            @endif
        @else
            <p class="type-body mt-2 text-text-2">Your poster has no reward text yet. Add one so customers know what they get.</p>
        @endif
        {{-- W2 (Amendment 38, Train A3-8): the staleness
             instruction, true in every state, rendered directly
             under the current-texts display it qualifies. --}}
        <p class="type-body mt-2 text-text-2">Printed posters keep the words they were printed with. If you change your rewards, print fresh posters: the old ones keep the old words.</p>
        <p class="mt-3">
            <a class="btn btn-secondary" href="{{ route('settings.rewards') }}">Edit reward texts</a>
        </p>
    </div>

    {{-- QF-2 (gap pack 03 Variant A, Amendment 29 [QF-1]): the
         standalone code as a slim utility panel below How
         redemption works. The plain code image at moderate
         size, the shipped code-only download and the copy-link
         joined field (same words, same routes, same behavior
         as the share strip), all at secondary weight; the
         panel contains no primary button. Its uses are
         deliberate trips (a designer making their own poster,
         a listing paste), never first-screen work. --}}
    <div class="panel mt-4" id="code-on-its-own">
        <h2 class="type-title-3 text-text-1">Your code on its own</h2>
        <div class="mt-4 flex flex-col gap-4 sm:flex-row sm:items-center">
            <span class="flex h-36 w-36 flex-none items-center justify-center rounded-lg border border-line bg-surface-0 p-2">
                <img src="{{ $imageUrl }}" alt="Your QR code" class="h-full w-full">
            </span>
            <div class="flex flex-col gap-3">
                <div class="joined" x-data="{ copied: false }">
                    <input type="text" readonly value="{{ $publicUrl }}" class="form-input form-input-app" aria-label="Your public referral link" x-ref="publicUrl">
                    <button type="button" class="btn btn-secondary"
                        @click="navigator.clipboard.writeText($refs.publicUrl.value); copied = true"
                        x-text="copied ? 'Copied' : 'Copy link'">Copy link</button>
                </div>
                <form method="POST" action="{{ route('owner.qr.download') }}" data-loading>
                    @csrf
                    <button type="submit" class="btn btn-secondary"><x-icon name="download" :size="20" /> Download code only (for your own designs)</button>
                </form>
            </div>
        </div>
    </div>

    <div class="card-danger mt-4">
        <h2 class="type-title-3 text-text-1">Replace all codes</h2>
        <div class="mt-3">
            <x-confirm-modal
                :action="route('owner.qr.regenerate')"
                id="regenerate-all-codes"
                title="Replace all codes?"
                confirm-label="Replace all codes"
                confirm-class="btn-danger">
                <x-slot:trigger><button type="button" class="btn btn-danger-ghost">Replace all codes</button></x-slot:trigger>
                <p>Every printed code for your store will stop working right away. Print new posters for each spot after you replace them.</p>
            </x-confirm-modal>
        </div>
    </div>
@endsection
