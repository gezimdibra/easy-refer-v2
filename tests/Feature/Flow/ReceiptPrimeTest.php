<?php

namespace Tests\Feature\Flow;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\Reward;
use App\Services\Referral\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * RS-N2 (Train B, Amendment 39): the referral-contagion prime on
 * the friend's REDEEMED receipt only (Gershon and Jiang, JMR
 * 2025: referred customers refer more, and a reminder that they
 * joined through a referral raises it further). The block is
 * truthful in exactly one state: the visit completed, the
 * friend's reward was applied, and the friend holds their own
 * share link. It is forbidden on the show, held, expired, and
 * unavailable redeem states, on every staff screen, and on the
 * referrer's claim receipt, and it drops off the receipt when
 * the business's flow is no longer live. The SMS half of the
 * research item is deferred (no such template exists; adding
 * the line there would be a new send, not a copy change).
 *
 * PIN RE-DERIVATION (ID-05, Amendment 48, recorded per the
 * design pass section 8 flag): this file originally pinned
 * the review ask's FORM on the redeem receipt — review text
 * present, the secondary-button classes ABSENT (Train B's
 * demotion of the ask to a plain link below the prime).
 * ID-05 Variant B deliberately reverses the classes half of
 * that pin: the ask is now a full-width secondary button
 * below the prime, in the exact form the claim receipt
 * always shipped, because a bare text link's click-through
 * sits near the floor of measurability and the two receipts
 * must speak one grammar. The pin's other half STANDS and is
 * still asserted below: the prime is the receipt's only
 * PRIMARY action and renders above the ask. The ask's
 * eligibility, cooldown, and recording live in ReviewAskTest.
 */
class ReceiptPrimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PhoneVerificationGateway::class, new FakePhoneVerificationGateway);
        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        Mail::fake();
    }

    /**
     * A redeemed visit: Reg referred Fri, Fri visited and holds
     * their own membership (minted at claim completion in the
     * real flow).
     *
     * @return array{0: Business, 1: Membership, 2: string, 3: Membership, 4: string}
     */
    private function redeemedVisit(array $businessOverrides = []): array
    {
        $business = World::business($businessOverrides);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$referrerMembership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$friendMembership, $friendShareToken] = World::membership($business, $friend);
        [, $redeemToken] = World::referral($referrerMembership, $friend, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);

        return [$business, $referrerMembership, $redeemToken, $friendMembership, $friendShareToken];
    }

    public function test_the_redeemed_receipt_carries_the_prime_with_the_friends_own_link(): void
    {
        [$business, , $redeemToken, , $friendShareToken] = $this->redeemedVisit(['google_review_url' => 'https://g.page/example']);

        $response = $this->get(route('refer.redeem.page', ['token' => $redeemToken]));

        $response->assertOk()
            ->assertSee('Pass it on')
            ->assertSee('You found '.$business->name.' through Reg.')
            ->assertSee('Share your link with a friend. They get $10 off your first visit, and you get $10 off when they visit.')
            ->assertSee('Share your link')
            // The friend's OWN share URL, never the referrer's.
            ->assertSee(route('refer.friend', ['token' => $friendShareToken]), false);

        $html = $response->getContent();
        $referrer = World::customer('+12125550999');
        [$referrerMembership] = World::membership($business, $referrer);
        $this->assertStringNotContainsString(
            route('refer.friend', ['token' => $referrerMembership->plainShareToken()]),
            $html,
        );
        // One primary next action in the block zone: the share
        // button (the share page's own mechanics, whose copy
        // button shares the primary styling, verbatim). The
        // review ask is the ID-05 secondary button below the
        // block (pin re-derived in the class docblock): same
        // full-width secondary form as the claim receipt,
        // never primary, never above the prime.
        $this->assertStringContainsString('btn btn-primary btn-flow', $html);
        $this->assertStringContainsString('Leave '.$business->name.' a Google review', $html);
        $this->assertStringContainsString('btn btn-secondary w-full mt-5', $html);
        $this->assertLessThan(
            strpos($html, 'Leave '.$business->name.' a Google review'),
            strpos($html, 'Pass it on'),
            'the prime renders above the review ask',
        );
    }

    public function test_the_prime_is_absent_from_every_other_redeem_state(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        $states = [
            'show' => ['state' => Referral::STATE_PENDING],
            // Option B (2026-10-28): the friend page has no held
            // state; a legacy-held-seeded pending referral renders
            // 'show', which must equally carry no prime.
            'flagged-pending' => ['state' => Referral::STATE_PENDING, 'hold' => true, 'hold_rules' => ['velocity_daily'], 'held_at' => now()],
            'expired' => ['state' => Referral::STATE_EXPIRED],
            'unavailable' => ['state' => Referral::STATE_VOIDED],
        ];

        $i = 0;
        foreach ($states as $label => $overrides) {
            $i++;
            // One friend per state: the pair key allows a single
            // live referral per (business, friend).
            $friend = World::customer('+1212555077'.$i, 'Fri', 'End');
            World::membership($business, $friend);
            [, $token] = World::referral($membership, $friend, $overrides);
            $html = $this->get(route('refer.redeem.page', ['token' => $token]))->assertOk()->getContent();
            $this->assertStringNotContainsString('Pass it on', $html, "prime leaked into the {$label} state");
            $this->assertStringNotContainsString('You found', $html, "prime line leaked into the {$label} state");
        }
    }

    public function test_the_prime_never_renders_on_staff_screens_or_the_claim_receipt(): void
    {
        [$business, $referrerMembership, $redeemToken] = $this->redeemedVisit();

        $staff = $this->get(route('redeem.show', ['token' => $redeemToken]))->assertOk()->getContent();
        $this->assertStringNotContainsString('Pass it on', $staff);
        $this->assertStringNotContainsString('You found', $staff);

        // The referrer's own claim receipt (they just spent an
        // earned reward) carries no prime either. (A different
        // friend than the visit above: BREAKER D-1's counted-pair
        // key admits only one redeemed referral per pair.)
        $friend = World::customer('+12125550778');
        [$referral] = World::referral($referrerMembership, $friend, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()]);
        [, $claimToken] = World::reward($referrerMembership, $referral, [
            'state' => Reward::STATE_CLAIMED, 'claimed_at' => now(),
        ]);
        $claim = $this->get(route('refer.claim.page', ['token' => $claimToken]))->assertOk()->getContent();
        $this->assertStringNotContainsString('Pass it on', $claim);
    }

    public function test_a_business_paused_after_the_visit_keeps_the_receipt_and_drops_the_block(): void
    {
        [$business, , $redeemToken] = $this->redeemedVisit();
        $business->forceFill(['billing_status' => 'past_due'])->save();
        // An undated past_due business is not paused; date the
        // episode 30 days back so the grace window has closed
        // (the Train A episode pattern: write under a frozen then).
        Carbon::setTestNow(now()->subDays(30));
        AuditLog::write('billing.past_due', null, [], $business->fresh(), null);
        Carbon::setTestNow(null);

        $html = $this->get(route('refer.redeem.page', ['token' => $redeemToken]))->assertOk()->getContent();
        $this->assertStringContainsString('Keep this screen as your receipt.', $html);
        $this->assertStringNotContainsString('Pass it on', $html);
    }

    public function test_referrer_reward_unset_uses_the_od2_fallback_and_friend_reward_unset_collapses_the_line(): void
    {
        [$business, , $redeemToken] = $this->redeemedVisit(['referrer_reward_text' => null]);
        $html = $this->get(route('refer.redeem.page', ['token' => $redeemToken]))->assertOk()->getContent();
        // OD-2: an unset referrer reward reads as the friend reward.
        $this->assertStringContainsString('and you get $10 off your first visit when they visit.', $html);

        // RE-SCOPED second half by Train F2 (Amendment 40),
        // flagged for the two-key reviewer: the original form
        // seeded a redeemed visit at a business that never had
        // reward texts — reachable only through the F-2 crash
        // path itself. The reachable post-gate state is a visit
        // redeemed while texts were set, then un-set (the R-6
        // Undo shape): the receipt keeps its facts, and the
        // prime drops entirely under Amendment 39's own
        // valid-state rule (the prime renders only while the
        // flow is still live), so the collapsed line renders
        // nowhere.
        [$business2, , $redeemToken2] = $this->redeemedVisit();
        $business2->forceFill(['friend_reward_text' => null, 'referrer_reward_text' => null])->save();
        $html2 = $this->get(route('refer.redeem.page', ['token' => $redeemToken2]))->assertOk()->getContent();
        $this->assertStringNotContainsString('Pass it on', $html2);
        $this->assertStringNotContainsString('Share your link with a friend, and you both get rewarded when they visit.', $html2);
    }

    public function test_a_whitespace_only_referrer_reward_falls_back_to_the_friend_reward_on_the_prime(): void
    {
        // Two-key review block (Train B): a whitespace-only
        // referrer text is unset (the SA-12 state), so the prime
        // must read with the OD-2 friend-reward fallback, never
        // an empty "you get  when they visit." promise.
        [, , $redeemToken] = $this->redeemedVisit(['referrer_reward_text' => '   ']);
        $html = $this->get(route('refer.redeem.page', ['token' => $redeemToken]))->assertOk()->getContent();
        $this->assertStringContainsString('Share your link with a friend. They get $10 off your first visit, and you get $10 off your first visit when they visit.', $html);
        $this->assertStringNotContainsString('you get  when', $html);
        $this->assertStringNotContainsString('you get .', $html);
    }

    public function test_the_receipt_link_cannot_be_used_by_the_friend_to_refer_themselves(): void
    {
        // Gaming acceptance (pass 3.5): the redeemed friend taps
        // their own link and claims with their own phone: the
        // model's hard block answers, and no referral is created.
        [, $referrerMembership, , $friendMembership] = $this->redeemedVisit();
        $friendMembership->forceFill(['created_at' => now()->subHour()])->save();

        $done = app(EnrollmentService::class)->completeClaim($friendMembership->refresh(), [
            'first_name' => 'Fri',
            'last_name' => 'End',
            'phone_e164' => '+12125550777',
            'consent' => true,
        ], '127.0.0.1', null);

        $this->assertSame('self_phone', $done['kind']);
        $this->assertSame(1, Referral::count(), 'only the original redeemed referral exists');

        // And with the business's own number.
        $ownerPhone = World::owner($friendMembership->business)->phone_e164;
        $done2 = app(EnrollmentService::class)->completeClaim($friendMembership->refresh(), [
            'first_name' => 'Fri',
            'last_name' => 'End',
            'phone_e164' => $ownerPhone,
            'consent' => true,
        ], '127.0.0.1', null);
        $this->assertSame('owner_phone', $done2['kind']);
        $this->assertSame(1, Referral::count());
    }

    public function test_a_third_person_claiming_through_the_receipt_link_makes_the_friend_the_referrer(): void
    {
        [, , , $friendMembership] = $this->redeemedVisit();
        $friendMembership->forceFill(['created_at' => now()->subHour()])->save();

        $done = app(EnrollmentService::class)->completeClaim($friendMembership->refresh(), [
            'first_name' => 'Thi',
            'last_name' => 'Rd',
            'phone_e164' => '+12125550321',
            'consent' => true,
        ], '127.0.0.1', null);

        $this->assertSame('created', $done['kind']);
        $this->assertSame($friendMembership->id, $done['referral']->referrer_membership_id);
    }

    public function test_a_redeemed_referral_whose_friend_has_no_membership_renders_the_receipt_without_the_block(): void
    {
        // Defensive: a redeemed referral minted outside the claim
        // flow (no friend membership exists) is still a receipt;
        // there is simply no share link to prime with.
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [, $redeemToken] = World::referral($membership, $friend, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);

        $html = $this->get(route('refer.redeem.page', ['token' => $redeemToken]))->assertOk()->getContent();
        $this->assertStringContainsString('Keep this screen as your receipt.', $html);
        $this->assertStringNotContainsString('Pass it on', $html);
    }
}
