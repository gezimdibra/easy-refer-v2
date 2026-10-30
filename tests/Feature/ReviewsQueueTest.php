<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\Reward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * The owner review queue (spec Sections 3.1, 5.6): held items with
 * plain-sentence reasons, release and deny, and tenant isolation.
 */
class ReviewsQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        $this->app->instance(PhoneVerificationGateway::class, new FakePhoneVerificationGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        Mail::fake();
    }

    // Legacy shape (§7.9): this fixture constructs a pre-Option-B
    // held referral that no live path creates since the conversion;
    // it is kept to prove the legacy drain/review surfaces still behave.
    private function heldReferral($business, array $rules = ['velocity_daily']): Referral
    {
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, [
            'hold' => true, 'hold_rules' => $rules, 'held_at' => now(),
        ]);

        return $referral;
    }

    // Legacy shape (§7.9): the held referral below is a pre-Option-B
    // shape no live path creates since the conversion, kept as legacy-drain coverage.
    public function test_queue_lists_held_referrals_with_plain_reasons(): void
    {
        $business = World::business();
        $this->heldReferral($business);

        $this->actingAs(World::owner($business))
            ->get(route('reviews'))
            ->assertOk()
            ->assertSee('Needs review')
            // Parties are named by first name only (design 3.4).
            ->assertSee('referred by Reg')
            ->assertDontSee('Fri End')
            ->assertDontSee('Reg Ular')
            ->assertSee('This referrer reached the daily limit of 5 rewarded referrals.')
            // Deny sits behind the consequence modal (F-04): the
            // finality and the no-notification fact render with the
            // queue, and nothing has fired yet.
            ->assertSee('Deny this referral?')
            ->assertSee('The customer is not notified automatically.')
            ->assertSee('Denying is final and cannot be undone.');
    }

    // Legacy shape (§7.9): the held referral below is a pre-Option-B
    // shape no live path creates since the conversion, kept as legacy-drain coverage.
    public function test_released_referral_can_be_redeemed_normally(): void
    {
        $business = World::business();
        $referral = $this->heldReferral($business);
        $token = $referral->redeem_token_encrypted;

        $this->actingAs(World::owner($business))
            ->post(route('reviews.referrals.release', ['referral' => $referral->id]))
            ->assertRedirect();

        $this->assertFalse($referral->fresh()->hold);
        $this->assertDatabaseHas('referral_events', ['event_type' => 'review_release', 'referral_id' => $referral->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'review.referral_released']);

        // The staff flow works again.
        $page = $this->get(route('redeem.show', ['token' => $token]))->assertOk()->assertSee('Mark as Redeemed');
        preg_match('/name="nonce" value="([^"]+)"/', $page->getContent(), $m);
        $this->post(route('redeem.confirm', ['token' => $token]), ['nonce' => $m[1]])->assertRedirect();
        $this->assertSame(Referral::STATE_REDEEMED, $referral->fresh()->state);
    }

    // Legacy shape (§7.9): the held referral below is a pre-Option-B
    // shape no live path creates since the conversion, kept as legacy-drain coverage.
    public function test_denied_referral_is_terminal_and_never_bills(): void
    {
        $business = World::business();
        $referral = $this->heldReferral($business);

        $this->actingAs(World::owner($business))
            ->post(route('reviews.referrals.deny', ['referral' => $referral->id]))
            ->assertRedirect()
            // The referral flash states both consequences; its
            // reward twin is pinned in the deny-reward test below
            // (Amendment 32 [PM-REV-1], Lane B F-1).
            ->assertSessionHas('status', 'Referral denied. It will not bill and no reward will be paid.');

        $this->assertSame(Referral::STATE_VOIDED, $referral->fresh()->state);

        $this->get(route('redeem.show', ['token' => $referral->redeem_token_encrypted]))
            ->assertNotFound()
            ->assertSee('This code is not active');

        $this->assertSame(0, \App\Models\BillingEvent::count());
        $this->assertSame(0, Reward::count());
    }

    public function test_held_reward_release_and_deny(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()]);
        [$reward] = World::reward($membership, $referral, [
            'state' => Reward::STATE_AVAILABLE,
            'hold' => true, 'hold_rules' => ['redemption_burst'], 'held_at' => now(),
            'notification_state' => 'queued',
        ]);

        $owner = World::owner($business);

        $this->actingAs($owner)->get(route('reviews'))
            ->assertOk()
            ->assertSee('Redemptions spiked well above the usual pace.');

        $this->actingAs($owner)
            ->post(route('reviews.rewards.deny', ['reward' => $reward->id]))
            ->assertRedirect()
            // Amendment 32 [PM-REV-1] (Lane B F-1): the reward
            // flash carries the consequence twin of the referral
            // flash: the reward will not be paid. It claims no
            // billing consequence, because denying a reward has
            // none, and the bare retired flash is gone.
            ->assertSessionHas('status', 'Reward denied. It will not be paid.');

        $this->assertSame(Reward::STATE_DENIED, $reward->fresh()->state);
    }

    public function test_sim_swap_banner_states_why_the_fact_matters(): void
    {
        // Amendment 32 [PM-REV-2] (Lane B F-3): the SIM-swap
        // banner was the catalog's only banner that stated a
        // fact without saying why it matters.
        $business = World::business();
        $this->heldReferral($business, ['sim_swap_step_up']);

        $this->actingAs(World::owner($business))
            ->get(route('reviews'))
            ->assertOk()
            ->assertSee('This phone number was recently moved to a new SIM card. Someone else may have taken over the number.');
    }

    public function test_queue_header_uses_the_row_pill_age_vocabulary(): void
    {
        // Amendment 32 [PM-REV-3] (Lane B F-4): the count line
        // rendered "oldest 0 days" for a same-day hold while the
        // row pill for the same item said "Waiting under an
        // hour" (AgeLabel). One vocabulary, both places.
        $business = World::business();
        $this->heldReferral($business);

        $this->actingAs(World::owner($business))
            ->get(route('reviews'))
            ->assertOk()
            ->assertSee('1 waiting · oldest under an hour')
            ->assertSee('Waiting under an hour')
            ->assertDontSee('oldest 0 days');
    }

    public function test_queue_header_states_no_age_when_no_held_at_is_recorded(): void
    {
        // held_at is nullable; the retired header claimed
        // "oldest 0 days" for an item with no recorded age.
        // The header now states the count and no age.
        $business = World::business();
        $item = $this->heldReferral($business);
        $item->forceFill(['held_at' => null])->save();

        $this->actingAs(World::owner($business))
            ->get(route('reviews'))
            ->assertOk()
            ->assertSee('1 waiting')
            ->assertDontSee('oldest');
    }

    public function test_warning_pill_legend_renders_only_when_an_item_waits_7_days_or_more(): void
    {
        // Amendment 32 [PM-REV-4] (Lane B F-5): at 7+ days the
        // waiting pill turns warning-coloured; the legend line
        // in the queue header says what that means, and it
        // renders only while a warning-state item is present.
        $legend = 'Items waiting 7 days or more have a highlighted waiting pill.';

        $fresh = World::business();
        $this->heldReferral($fresh);

        $this->actingAs(World::owner($fresh))
            ->get(route('reviews'))
            ->assertOk()
            ->assertDontSee($legend);

        $stale = World::business();
        $old = $this->heldReferral($stale);
        $old->forceFill(['held_at' => now()->subDays(8)])->save();

        $this->actingAs(World::owner($stale))
            ->get(route('reviews'))
            ->assertOk()
            ->assertSee($legend)
            ->assertSee('oldest 8 days');
    }

    public function test_empty_state_never_sits_above_a_held_reward(): void
    {
        // T5-F3: the "Nothing is waiting for review" panel was the
        // held-REFERRAL loop's empty branch, so with no held
        // referrals but one held reward it printed directly above
        // that reward's Release/Deny panel. The empty state now
        // belongs to the whole Needs review queue.
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()]);
        World::reward($membership, $referral, [
            'state' => Reward::STATE_AVAILABLE,
            'hold' => true, 'hold_rules' => ['redemption_burst'], 'held_at' => now(),
            'notification_state' => 'queued',
        ]);

        $owner = World::owner($business);

        $this->actingAs($owner)->get(route('reviews'))
            ->assertOk()
            ->assertSee("Reg's reward:", false)
            ->assertSee('Release')
            ->assertDontSee('Nothing is waiting for review.');

        // And with a genuinely empty queue the panel still shows.
        $empty = World::business();
        $this->actingAs(World::owner($empty))->get(route('reviews'))
            ->assertOk()
            ->assertSee('Nothing is waiting for review.');
    }

    public function test_records_zone_renders_new_headings_explainers_and_empty_states(): void
    {
        // Amendment 31 [ON-REV-1..4]: the records zone sits below
        // the decision queue under its own heading, and every list
        // carries its ratified heading, explainer, and empty state.
        $business = World::business();

        $this->actingAs(World::owner($business))
            ->get(route('reviews'))
            ->assertOk()
            ->assertSee('Worth knowing')
            ->assertSee('Nothing here needs a decision. These lists are for your records.')
            ->assertSee('Redeemed at the counter')
            ->assertSee('When a customer shows their reward code, a team member scans it with their own phone and taps Mark as Redeemed. Nobody signs in to do it, so these redemptions show no name. That is the normal way redemption works.')
            ->assertSee('Nothing here yet. Redemptions finished at the counter will be listed here.')
            ->assertSee('New phone numbers')
            ->assertSee('When a customer links a new phone number, their claims pause on their own for 7 days as a safety check. There is nothing for you to do. These customers linked a number in the last 7 days:')
            ->assertSee('Nothing here yet. Customers who link a new number will be listed here while the 7-day wait runs.')
            ->assertSee('Codes that could not be used')
            ->assertSee('A customer showed a code that did not work. The visit did not count.')
            ->assertSee('Nothing here yet. If a customer shows a code that cannot be used, it will be listed here with the date.')
            // The retired framing renders nowhere on the page.
            ->assertDontSee('staff account')
            ->assertDontSee('cooling window')
            ->assertDontSee('Redeemed without a staff account')
            ->assertDontSee('Pending number links')
            ->assertDontSee('Rejected presentations');
    }

    public function test_number_link_wait_is_read_from_config_not_hard_coded(): void
    {
        config(['referrals.number_link_cooling_days' => 9]);
        $business = World::business();

        $this->actingAs(World::owner($business))
            ->get(route('reviews'))
            ->assertOk()
            ->assertSee('their claims pause on their own for 9 days as a safety check')
            ->assertSee('These customers linked a number in the last 9 days:')
            ->assertSee('while the 9-day wait runs.')
            ->assertDontSee('for 7 days as a safety check');
    }

    public function test_hold_reason_sentences_use_amendment_31_wording(): void
    {
        // [ON-REV-5]: both rewritten sentences render in the queue
        // banners, and the retired words render nowhere.
        $business = World::business();
        $this->heldReferral($business, ['unattributed_share']);

        $referrer = World::customer('+12125550888', 'Oth', 'Er');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550666', 'Sec', 'Ond');
        World::referral($membership, $friend, [
            'hold' => true, 'hold_rules' => ['number_link_cooling'], 'held_at' => now(),
        ]);

        $this->actingAs(World::owner($business))
            ->get(route('reviews'))
            ->assertOk()
            ->assertSee('Most recent redemptions do not show who redeemed them.')
            ->assertSee('This customer linked a new phone number in the last 7 days, so this claim is paused as a safety check.')
            ->assertDontSee('staff account')
            ->assertDontSee('cooling window')
            ->assertDontSee('Most recent redemptions were not attributed')
            ->assertDontSee('This claim arrived during the');
    }

    public function test_counter_redemption_row_uses_business_local_time(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        // 02:30 UTC is the previous evening in New York, so the
        // local date and the UTC date differ by a day.
        $redeemedAt = Carbon::now('UTC')->subDay()->setTime(2, 30);
        $localDate = $business->localTime($redeemedAt)->format('F j, Y');
        $utcDate = $redeemedAt->format('F j, Y');
        $this->assertNotSame($utcDate, $localDate);

        World::referral($membership, $friend, [
            'state' => Referral::STATE_REDEEMED,
            'attribution_state' => 'unattributed',
            'redeemed_at' => $redeemedAt,
        ]);

        $this->actingAs(World::owner($business))
            ->get(route('reviews'))
            ->assertOk()
            ->assertSee("Fri, referred by Reg, redeemed {$localDate}")
            ->assertDontSee("redeemed {$utcDate}");
    }

    public function test_number_link_row_uses_business_local_time(): void
    {
        $business = World::business();
        $customer = World::customer('+12125550999', 'Reg', 'Ular');
        $linkedAt = Carbon::now('UTC')->subDay()->setTime(2, 30);
        $localDate = $business->localTime($linkedAt)->format('F j, Y');
        $utcDate = $linkedAt->format('F j, Y');
        $this->assertNotSame($utcDate, $localDate);

        $event = ReferralEvent::record('number_linked', 'customer', $business, null, null, null, [
            'customer_id' => $customer->id,
        ]);
        $event->forceFill(['created_at' => $linkedAt])->save();

        $this->actingAs(World::owner($business))
            ->get(route('reviews'))
            ->assertOk()
            ->assertSee('New phone numbers')
            ->assertSee("Reg linked a new number on {$localDate}.")
            ->assertDontSee("linked a new number on {$utcDate}.");
    }

    public function test_rejection_rows_name_the_recorded_reason_in_local_time(): void
    {
        // [ON-REV-4]: each row's line is mapped from the event's
        // recorded reason; anything unmapped falls back.
        $business = World::business();
        $cases = [
            'expired_token' => ['A code that had expired was shown on October 20, 2026.', Carbon::create(2026, 10, 20, 12, 0, 0, 'UTC')],
            'voided_token' => ['A code that is no longer active was shown on October 21, 2026.', Carbon::create(2026, 10, 21, 12, 0, 0, 'UTC')],
            'denied_token' => ['A code for a denied reward was shown on October 22, 2026.', Carbon::create(2026, 10, 22, 12, 0, 0, 'UTC')],
            'unknown_token' => ['A code that could not be used was shown on October 23, 2026.', Carbon::create(2026, 10, 23, 12, 0, 0, 'UTC')],
        ];
        foreach ($cases as $reason => [, $at]) {
            $event = ReferralEvent::record('redemption_rejected', 'counter', $business, null, null, null, [
                'reason' => $reason,
            ]);
            $event->forceFill(['created_at' => $at])->save();
        }

        $response = $this->actingAs(World::owner($business))
            ->get(route('reviews'))
            ->assertOk()
            ->assertSee('Codes that could not be used')
            ->assertDontSee('was presented on');
        foreach ($cases as [$line]) {
            $response->assertSee($line);
        }
    }

    public function test_another_business_cannot_see_or_touch_the_queue(): void
    {
        $business = World::business();
        $other = World::business();
        $referral = $this->heldReferral($business);

        $this->actingAs(World::owner($other))
            ->get(route('reviews'))
            ->assertOk()
            ->assertDontSee('Fri End');

        $this->actingAs(World::owner($other))
            ->post(route('reviews.referrals.release', ['referral' => $referral->id]))
            ->assertNotFound();

        $this->assertTrue($referral->fresh()->hold);
    }
}
