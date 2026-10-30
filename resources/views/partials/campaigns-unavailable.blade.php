{{--
    The campaigns unavailable state (FE-CAMP-02): one partial
    serving the index and create panels, so the words cannot
    drift between them (the BILL-PD-01 discipline). Two facts
    can make campaigns unavailable, and the panel speaks to
    the one the owner can act on:

    - the business's own program is not active: the panel
      gains the reason and the fix — "Campaigns open once
      your program is active." with the Add a card action
      into Billing, the QR-INACTIVE-01a pattern (words only
      when the account itself is not active, mirroring
      QR-INACTIVE-01b: no card resolves a paused account);
    - the business is active and only the platform gateway
      is unconfigured: the single line, unchanged — nothing
      is asked of the owner because nothing is theirs to fix.
--}}
<div class="panel mt-5">
    <p class="text-text-1">Text campaigns are not available right now.</p>
    @if (! $business->flowIsActive())
        <p class="mt-2 text-text-2">Campaigns open once your program is active.</p>
        @if ($business->isActive() && ! $business->hasFriendRewardText())
            {{-- Train F2 (Amendment 40, F2-GATE-01 twin): the
                 missing step is named in dependency order —
                 rewards before card — so a card-holding owner is
                 never sent to add a card they already have. --}}
            <p class="mt-2"><a class="font-semibold text-link hover:underline" href="{{ route('settings.rewards') }}">Set your rewards</a></p>
        @elseif ($business->isActive() && $business->flowBlockReason() === 'dunning_paused')
            {{-- Activation redesign (census C-26): the
                 dunning cause names its own remedy — the
                 billing page, where the past-due chrome owns
                 the episode. --}}
            <p class="mt-2"><a class="font-semibold text-link hover:underline" href="{{ route('settings.billing') }}">Go to Billing</a></p>
        @elseif ($business->isActive())
            <p class="mt-2"><a class="font-semibold text-link hover:underline" href="{{ route('settings.billing') }}#card">Add a card</a></p>
        @endif
    @endif
</div>
