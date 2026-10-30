{{--
    F2 (Amendment 45): the campaign send path's flow-state
    truth, one partial for compose and confirm so the words
    cannot drift. A closed business's program has ended and
    sending is blocked (the send POST refuses too; this is
    the surface that says so before the owner tries). Any
    other inactive flow may still send (campaigns also bring
    past customers back directly), but the referral
    consequence is named, branched by cause (two-key
    Condition 1, 2026-10-28) so every clause is true in the
    state where it renders:

    - textless (no friend reward text): friends arriving
      from these texts are stopped at the flow gate before
      any referral exists, so they cannot earn, hold nothing
      to redeem, and no visit of theirs counts. The
      already-earned sentence is NOT added here: when both
      reward texts are blank an in-flight visit cannot be
      finished either (the RedemptionService missing-text
      backstop), so a blanket "still redeemable" promise
      would overclaim in that shape.
    - inactive with the text set (past-due paused, no card
      yet, platform disabled): new arrivals are stopped at
      the same gate, so the claim is scoped to them — but
      claimReward and redeemReferral carry no pause,
      billing, or status gate, so a visit already in flight
      can still complete and a reward already earned can
      still be redeemed (the dunning copy's own promise).
      The sentence must never deny that redemption.

    An active flow renders nothing.
--}}
@if (($flowState ?? 'active') === 'closed')
    <div class="banner banner-warning mt-4">
        <p class="font-semibold">{{ $closedLine }}</p>
    </div>
@elseif (($flowState ?? 'active') === 'textless')
    <div class="banner banner-warning mt-4">
        <p class="font-semibold">Your program is not active right now. Friends who come from these texts cannot earn or redeem rewards, and their visits will not count.</p>
    </div>
@elseif (($flowState ?? 'active') === 'inactive')
    <div class="banner banner-warning mt-4">
        <p class="font-semibold">Your program is not active right now. Friends who come from these texts cannot earn rewards, and their visits will not count. Rewards already earned can still be redeemed.</p>
    </div>
@endif
