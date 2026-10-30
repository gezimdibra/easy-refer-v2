<?php

namespace Tests\Feature\Flow;

use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\Reward;
use App\Services\Flow\ReviewAsk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\World;
use Tests\TestCase;

/**
 * ID-05 (Amendment 48): the deliberate review ask. ONE
 * eligibility service governs both receipts; the suppressors
 * are exactly {no link, 90-day impression cooldown, receipt
 * not completed}; holds and flow state are never suppressors.
 * Asked state is append-only referral_events rows keyed by
 * membership; clicks route through our redirect so CTR is
 * countable. No "reviews gained" number exists anywhere.
 */
class ReviewAskTest extends TestCase
{
    use RefreshDatabase;

    private const LINK = 'https://g.page/example/review';

    /** @return array{0: Business, 1: Membership, 2: string, 3: Membership} business, referrer membership, redeem token, friend membership */
    private function redeemedVisit(array $businessOverrides = []): array
    {
        $business = World::business(array_merge(['google_review_url' => self::LINK], $businessOverrides));
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$referrerMembership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$friendMembership] = World::membership($business, $friend);
        [, $redeemToken] = World::referral($referrerMembership, $friend, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);

        return [$business, $referrerMembership, $redeemToken, $friendMembership];
    }

    private function shownCount(): int
    {
        return ReferralEvent::query()->where('event_type', ReviewAsk::EVENT_SHOWN)->count();
    }

    public function test_both_receipts_render_the_shared_secondary_button_and_record_one_impression_each(): void
    {
        [$business, $referrerMembership, $redeemToken] = $this->redeemedVisit();

        $html = $this->get(route('refer.redeem.page', ['token' => $redeemToken]))->assertOk()->getContent();
        $this->assertStringContainsString('btn btn-secondary w-full mt-5', $html);
        $this->assertStringContainsString('Leave '.$business->name.' a Google review', $html);
        // The href is OUR click route, never Google directly.
        $this->assertStringContainsString(route('refer.redeem.review', ['token' => $redeemToken]), $html);
        $this->assertSame(1, $this->shownCount());

        $referral = Referral::query()->first();
        [, $claimToken] = World::reward($referrerMembership, $referral, [
            'state' => Reward::STATE_CLAIMED, 'claimed_at' => now(),
        ]);
        $claim = $this->get(route('refer.claim.page', ['token' => $claimToken]))->assertOk()->getContent();
        $this->assertStringContainsString('btn btn-secondary w-full mt-5', $claim);
        $this->assertStringContainsString(route('refer.claim.review', ['token' => $claimToken]), $claim);
        $this->assertSame(2, $this->shownCount(), 'a different membership has its own clock');
    }

    public function test_a_receipt_keeps_its_own_ask_across_reviews_without_recording_again(): void
    {
        [, , $redeemToken] = $this->redeemedVisit();

        for ($i = 0; $i < 10; $i++) {
            $html = $this->get(route('refer.redeem.page', ['token' => $redeemToken]))->assertOk()->getContent();
            $this->assertStringContainsString('a Google review', $html);
        }
        $this->assertSame(1, $this->shownCount());
    }

    public function test_the_cooldown_is_shared_across_surfaces_and_expires_at_90_days(): void
    {
        [$business, , $redeemToken, $friendMembership] = $this->redeemedVisit();
        $this->get(route('refer.redeem.page', ['token' => $redeemToken]))->assertOk();
        $this->assertSame(1, $this->shownCount());

        // The same person, as a referrer claiming a reward at
        // the same business inside 90 days: no ask, no record.
        $otherReferrer = World::customer('+12125550111', 'Oth', 'Er');
        [$otherMembership] = World::membership($business, $otherReferrer);
        $friendCustomer = $friendMembership->customer;
        [$theirReferral] = World::referral($otherMembership, World::customer('+12125550222', 'New', 'One'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);
        // The friend (now claiming as the referrer of their own
        // visit chain) — use the friend's own membership reward:
        [$ownReferral] = World::referral($friendMembership, World::customer('+12125550333', 'Thi', 'Rd'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);
        [, $claimToken] = World::reward($friendMembership, $ownReferral, [
            'state' => Reward::STATE_CLAIMED, 'claimed_at' => now(),
        ]);
        $claim = $this->get(route('refer.claim.page', ['token' => $claimToken]))->assertOk()->getContent();
        $this->assertStringNotContainsString('a Google review', $claim);
        $this->assertSame(1, $this->shownCount());

        // At day 91 a new receipt asks again and restarts the clock.
        Carbon::setTestNow(now()->addDays(91));
        [$ownReferral2] = World::referral($friendMembership, World::customer('+12125550444', 'Fou', 'Rth'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);
        [, $claimToken2] = World::reward($friendMembership, $ownReferral2, [
            'state' => Reward::STATE_CLAIMED, 'claimed_at' => now(),
        ]);
        $claim2 = $this->get(route('refer.claim.page', ['token' => $claimToken2]))->assertOk()->getContent();
        $this->assertStringContainsString('a Google review', $claim2);
        $this->assertSame(2, $this->shownCount());
    }

    public function test_a_held_referral_receipt_still_shows_the_ask(): void
    {
        // Post-r46 Option B: the friend always completes; only
        // the referrer reward + billing are held. The receipt
        // is redeemed and the ask renders identically.
        [$business, $referrerMembership, $redeemToken] = $this->redeemedVisit();
        $referral = Referral::query()->first();
        $referral->reward?->forceFill(['hold' => true, 'hold_rules' => ['device_signal_reuse']])->save();
        \App\Models\BillingEvent::create([
            'business_id' => $business->id, 'referral_id' => $referral->id,
            'amount_cents' => 0, 'coverage' => 'held',
        ]);
        // Mint the held referrer reward the conversion creates.
        if ($referral->reward === null) {
            World::reward($referrerMembership, $referral, ['hold' => true, 'hold_rules' => ['device_signal_reuse']]);
        }

        $html = $this->get(route('refer.redeem.page', ['token' => $redeemToken]))->assertOk()->getContent();
        $this->assertStringContainsString('Leave '.$business->name.' a Google review', $html);
    }

    public function test_a_paused_business_receipt_still_shows_the_ask(): void
    {
        [$business, , $redeemToken] = $this->redeemedVisit();
        // Date the past-due episode 30 days back (the Train A
        // episode pattern), so at render the grace has closed
        // and the flow is paused.
        Carbon::setTestNow(now()->subDays(30));
        \App\Models\AuditLog::write('billing.past_due', null, [], $business, null);
        Carbon::setTestNow(null);
        $business->forceFill(['billing_status' => 'past_due'])->save();

        $html = $this->get(route('refer.redeem.page', ['token' => $redeemToken]))->assertOk()->getContent();
        $this->assertStringContainsString('Keep this screen as your receipt.', $html);
        $this->assertStringNotContainsString('Pass it on', $html, 'the prime drops when the flow pauses');
        $this->assertStringContainsString('a Google review', $html, 'the ask follows the receipt, not the flow');
    }

    public function test_no_link_means_no_ask_and_nothing_recorded(): void
    {
        [$business, , $redeemToken] = $this->redeemedVisit(['google_review_url' => null]);

        $html = $this->get(route('refer.redeem.page', ['token' => $redeemToken]))->assertOk()->getContent();
        $this->assertStringNotContainsString('Google review', $html);
        $this->assertSame(0, $this->shownCount());
    }

    public function test_clicks_record_once_and_redirect_to_the_current_link(): void
    {
        [$business, , $redeemToken] = $this->redeemedVisit();
        $this->get(route('refer.redeem.page', ['token' => $redeemToken]))->assertOk();

        for ($i = 0; $i < 5; $i++) {
            $this->get(route('refer.redeem.review', ['token' => $redeemToken]))
                ->assertRedirect(self::LINK);
        }
        $this->assertSame(1, ReferralEvent::query()->where('event_type', ReviewAsk::EVENT_CLICKED)->count());

        // The destination is re-read live: change the link and
        // the next tap follows the new one.
        $business->forceFill(['google_review_url' => 'https://g.page/other/review'])->save();
        $this->get(route('refer.redeem.review', ['token' => $redeemToken]))
            ->assertRedirect('https://g.page/other/review');

        // Remove it: the tap falls back to the receipt, never
        // an error page or a stale URL.
        $business->forceFill(['google_review_url' => null])->save();
        $this->get(route('refer.redeem.review', ['token' => $redeemToken]))
            ->assertRedirect(route('refer.redeem.page', ['token' => $redeemToken]));
    }

    public function test_the_ask_block_carries_no_reward_text_no_stars_and_no_banned_words(): void
    {
        [$business, , $redeemToken] = $this->redeemedVisit();
        $html = $this->get(route('refer.redeem.page', ['token' => $redeemToken]))->assertOk()->getContent();

        $start = strpos($html, 'btn btn-secondary w-full mt-5');
        $this->assertNotFalse($start);
        $block = substr($html, $start, 400);
        foreach (['5 stars', 'five stars', 'positive', 'great review', 'good review', 'if you loved', 'discount', '★', '$10 off'] as $banned) {
            $this->assertStringNotContainsString($banned, $block, "banned vocabulary in the ask block: {$banned}");
        }
        $this->assertStringNotContainsString('modal', $block);
    }

    public function test_the_owner_stat_counts_our_events_and_carries_the_honesty_line(): void
    {
        [$business, , $redeemToken] = $this->redeemedVisit();
        $this->get(route('refer.redeem.page', ['token' => $redeemToken]))->assertOk();
        $this->get(route('refer.redeem.review', ['token' => $redeemToken]));

        $owner = World::owner($business);
        $html = $this->actingAs($owner)->get(route('settings.reviews'))->assertOk()->getContent();
        $this->assertStringContainsString('In the last 30 days, 1 customer saw the review link on their receipt, and 1 tapped it.', $html);
        $this->assertStringContainsString('Whether they left a review is on Google. We cannot see it here.', $html);
        // REVIEW-05/06: the corrected sentences, and the false
        // ones gone.
        $this->assertStringContainsString('Customers see this link on their receipt after a visit, and again when they claim a reward, at most once every 90 days for each customer.', $html);
        $this->assertStringContainsString("customers just won't see the review link on their receipts", $html);
        $this->assertStringNotContainsString('after a visit is counted, and again after they claim a reward', $html);
        $this->assertStringNotContainsString('see a review button', $html);
        // The prohibited stat, absent as a concept.
        $this->assertStringNotContainsString('reviews gained', $html);
        $this->assertStringNotContainsString('Reviews gained', $html);
    }

    public function test_the_owner_stat_renders_true_zeros_with_the_link_set(): void
    {
        $business = World::business(['google_review_url' => self::LINK]);
        $owner = World::owner($business);
        $html = $this->actingAs($owner)->get(route('settings.reviews'))->assertOk()->getContent();
        $this->assertStringContainsString('In the last 30 days, 0 customers saw the review link on their receipt, and 0 tapped it.', $html);
        $this->assertStringContainsString('Whether they left a review is on Google. We cannot see it here.', $html);
    }

    public function test_a_redeemed_referral_without_a_friend_membership_renders_unrecorded(): void
    {
        $business = World::business(['google_review_url' => self::LINK]);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [, $redeemToken] = World::referral($membership, $friend, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);

        $html = $this->get(route('refer.redeem.page', ['token' => $redeemToken]))->assertOk()->getContent();
        $this->assertStringContainsString('a Google review', $html, 'the shipped behaviour is preserved');
        $this->assertSame(0, $this->shownCount(), 'measurement never counts an unkeyable render');
    }
}
