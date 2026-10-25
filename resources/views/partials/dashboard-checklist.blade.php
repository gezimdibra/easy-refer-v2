{{--
    The setup checklist card (spec Section 5.8), included by the
    Overview in one of two positions (DG-2, release 08b): in its
    original place under the action cards while the poster step
    is open, and below the tiles once the poster is printed, so
    setup furniture stops outranking the day's numbers. It
    renders only while a step is open and it has not been
    dismissed. Row states share one pattern: a success circle
    with the check glyph when done, a circle-alert glyph when
    open, and open rows link to the step's surface. The card row
    links to the billing page in Settings (DG-2 closed, billing
    UI build unit BU-3); the controller derives card_on_file
    from the Square customer record.
--}}
    @php($checklistTotal = count($checklist))
    @if ($checklistDone < $checklistTotal && ! $checklistDismissed)
        <div class="card mt-4">
            <div class="flex items-center justify-between">
                <h2 class="type-title-3 text-text-1">Setup checklist</h2>
                <form method="POST" action="{{ route('dashboard.checklist.dismiss') }}" data-loading>
                    @csrf
                    <button type="submit" class="btn btn-ghost btn-sm">Dismiss</button>
                </form>
            </div>
            <p class="type-body-sm mt-1 text-text-2">{{ $checklistDone }} of {{ $checklistTotal }} done</p>
            <div class="progress-track mt-3" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $checklistTotal }}" aria-valuenow="{{ $checklistDone }}" aria-label="Setup progress">
                <div class="progress-fill" style="width: {{ $checklistTotal > 0 ? (int) round($checklistDone / $checklistTotal * 100) : 0 }}%"></div>
            </div>
            <ul class="mt-4 space-y-2">
                <li class="flex items-center gap-2.5">
                    @if ($checklist['email_verified'])
                        <span class="flex items-center gap-2.5"><span class="flex h-5 w-5 flex-none items-center justify-center rounded-full bg-success-fill text-success-strong"><x-icon name="check" :size="16" /></span> Verify your email</span>
                    @else
                        <span class="flex-none text-text-3" aria-hidden="true"><x-icon name="circle-alert" :size="20" /></span> <a class="font-semibold text-link hover:underline" href="{{ route('verification.notice') }}">Verify your email</a>
                    @endif
                </li>
                <li class="flex items-center gap-2.5">
                    @if ($checklist['reward_text'])
                        <span class="flex items-center gap-2.5"><span class="flex h-5 w-5 flex-none items-center justify-center rounded-full bg-success-fill text-success-strong"><x-icon name="check" :size="16" /></span> Set your rewards</span>
                    @else
                        <span class="flex-none text-text-3" aria-hidden="true"><x-icon name="circle-alert" :size="20" /></span> <a class="font-semibold text-link hover:underline" href="{{ route('settings.rewards') }}">Set your rewards</a>
                    @endif
                </li>
                <li class="flex items-center gap-2.5">
                    @if ($checklist['poster_downloaded'])
                        <span class="flex items-center gap-2.5"><span class="flex h-5 w-5 flex-none items-center justify-center rounded-full bg-success-fill text-success-strong"><x-icon name="check" :size="16" /></span> Print your QR code</span>
                    @else
                        <span class="flex-none text-text-3" aria-hidden="true"><x-icon name="circle-alert" :size="20" /></span> <a class="font-semibold text-link hover:underline" href="{{ route('owner.qr') }}">Print your QR code</a>
                    @endif
                </li>
                <li class="flex items-center gap-2.5">
                    @if ($checklist['card_on_file'])
                        <span class="flex items-center gap-2.5"><span class="flex h-5 w-5 flex-none items-center justify-center rounded-full bg-success-fill text-success-strong"><x-icon name="check" :size="16" /></span> Add a card (required to activate your QR code)</span>
                    @else
                        <span class="flex-none text-text-3" aria-hidden="true"><x-icon name="circle-alert" :size="20" /></span> <a class="font-semibold text-link hover:underline" href="{{ route('settings.billing') }}">Add a card (required to activate your QR code)</a>
                    @endif
                </li>
                <li class="flex items-center gap-2.5">
                    @if ($checklist['review_link'])
                        <span class="flex items-center gap-2.5"><span class="flex h-5 w-5 flex-none items-center justify-center rounded-full bg-success-fill text-success-strong"><x-icon name="check" :size="16" /></span> Add your Google review link</span>
                    @else
                        <span class="flex-none text-text-3" aria-hidden="true"><x-icon name="circle-alert" :size="20" /></span> <a class="font-semibold text-link hover:underline" href="{{ route('settings.reviews') }}">Add your Google review link</a>
                    @endif
                </li>
                <li class="flex items-center gap-2.5">
                    @if ($checklist['test_referral'])
                        <span class="flex items-center gap-2.5"><span class="flex h-5 w-5 flex-none items-center justify-center rounded-full bg-success-fill text-success-strong"><x-icon name="check" :size="16" /></span> Send yourself a test referral</span>
                    @else
                        <span class="flex-none text-text-3" aria-hidden="true"><x-icon name="circle-alert" :size="20" /></span> <a class="font-semibold text-link hover:underline" href="{{ route('owner.qr') }}">Send yourself a test referral</a>
                    @endif
                </li>
            </ul>
        </div>
    @endif

