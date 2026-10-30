<?php

namespace Tests\Feature\Campaigns;

use App\Contracts\MarketingSmsGateway;
use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Models\SmsMessage;
use App\Services\Owner\ActionCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeMarketingSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * ID-02 (Amendment 48): the recognition card, the
 * recipient-locked draft, recompute-at-send, the lock pins,
 * the skip states, and the F1 interaction. The send is an
 * owner-approved campaign of exactly 1 — never a system
 * send, never free.
 */
class TopReferrerRecognitionTest extends TestCase
{
    use RefreshDatabase;

    private FakeMarketingSmsGateway $gateway;

    private int $seq = 9300;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new FakeMarketingSmsGateway;
        $this->app->instance(MarketingSmsGateway::class, $this->gateway);
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-11-10 09:00:00', 'America/New_York'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(Business $business, string $first = 'Sam'): Membership
    {
        $phone = '+1212555'.str_pad((string) $this->seq++, 4, '0', STR_PAD_LEFT);
        [$membership] = World::membership($business, World::customer($phone, $first, 'Reed'));
        $membership->forceFill(['sms_consent' => true, 'consent_source' => 'customer_checkbox'])->save();

        return $membership->fresh();
    }

    private function visit(Membership $referrer, array $opts = []): Referral
    {
        $phone = '+1212555'.str_pad((string) $this->seq++, 4, '0', STR_PAD_LEFT);
        [$referral] = World::referral($referrer, World::customer($phone, 'Fri', 'End'), [
            'state' => Referral::STATE_REDEEMED,
            'redeemed_at' => Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-15 12:00:00', 'America/New_York')->setTimezone('UTC'),
        ]);
        BillingEvent::create([
            'business_id' => $referrer->business_id, 'referral_id' => $referral->id,
            'amount_cents' => 200, 'coverage' => $opts['coverage'] ?? 'billable',
        ]);
        World::reward($referrer, $referral, $opts['reward'] ?? []);

        return $referral;
    }

    private function visits(Membership $referrer, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->visit($referrer);
        }
    }

/**
     * ACTIVATION REDESIGN fixture note (2026-10-30): these
     * prompt scenarios model established businesses whose
     * visits already fill the free allowance (5+ counted
     * BillingEvents). Under the allowance gate a cardless
     * business in that state is PAUSED and every growth
     * prompt stands down by design, so the fixtures carry
     * the card their billing history implies.
     */
    private function succeededCard(\App\Models\Business $business): void
    {
        \App\Models\PaymentMethod::create([
            'business_id' => $business->id, 'square_card_id' => 'card_prompt_1', 'card_brand' => 'Visa',
            'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030,
            'is_default' => true, 'state' => \App\Models\PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_prompt_'.$business->id,
        ]);
    }

    /** @return array<string, array> */
    private function items(Business $business): array
    {
        $owner = World::owner($business);

        return collect(app(ActionCenter::class)->pageState($business, $owner)['items'])->keyBy('key')->all();
    }

    private function cardBusiness(array $overrides = []): array
    {
        $business = World::business($overrides);
        $this->succeededCard($business);
        $leader = $this->member($business);
        $this->visits($leader, 4);

        return [$business, $leader];
    }

    /** One delivered campaign touch for a member at a moment (an F1 touch). */
    private function touch(Membership $member, Carbon $at): void
    {
        $other = SmsCampaign::create([
            'business_id' => $member->business_id, 'body' => 'Hello',
            'status' => SmsCampaign::STATUS_SENT, 'sent_at' => $at,
            'created_by' => World::owner($member->business)->id,
        ]);
        $row = SmsCampaignRecipient::create([
            'campaign_id' => $other->id, 'membership_id' => $member->id,
            'customer_id' => $member->customer_id, 'phone_e164' => $member->customer->phone_e164,
            'delivery_state' => SmsCampaignRecipient::STATE_DELIVERED,
            'segments' => 1, 'amount_cents' => 2,
        ]);
        // created_at is not fillable; the F1 read keys on it,
        // so the touch's age is set the way the F1 fixtures do.
        $row->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }

    private function draftFor(Business $business): SmsCampaign
    {
        $owner = World::owner($business);
        $this->actingAs($owner)->post(route('campaigns.recognition'), ['standings_month' => '2026-10']);

        return SmsCampaign::query()->where('provenance', SmsCampaign::PROVENANCE_TOP_REFERRER)->firstOrFail();
    }

    public function test_the_card_renders_with_the_count_and_the_leader(): void
    {
        [$business] = $this->cardBusiness();

        $items = $this->items($business);
        $this->assertArrayHasKey('top_referrer_recognition', $items);
        $this->assertSame(85, $items['top_referrer_recognition']['urgency']);
        $this->assertStringContainsString('Your top referrer in October: Sam Reed, with 4 friends who visited.', $items['top_referrer_recognition']['line']);
        $this->assertSame('Send a thank-you text', $items['top_referrer_recognition']['action_label']);
    }

    public function test_skip_states_produce_silence_and_recovery_inside_the_window_rerenders(): void
    {
        // Past-due IN GRACE: no card.
        [$graceBiz] = $this->cardBusiness(['billing_status' => 'past_due']);
        $this->assertArrayNotHasKey('top_referrer_recognition', $this->items($graceBiz->fresh()));

        // Closed: no card, ever.
        [$closedBiz] = $this->cardBusiness(['status' => 'closed']);
        $this->assertArrayNotHasKey('top_referrer_recognition', $this->items($closedBiz->fresh()));

        // Textless: no card.
        [$textlessBiz] = $this->cardBusiness(['friend_reward_text' => null]);
        $this->assertArrayNotHasKey('top_referrer_recognition', $this->items($textlessBiz->fresh()));

        // Disabled / not active: no card.
        [$disabledBiz] = $this->cardBusiness(['status' => 'disabled']);
        $this->assertArrayNotHasKey('top_referrer_recognition', $this->items($disabledBiz->fresh()));

        // Recovery inside the window re-renders the card.
        $textlessBiz->forceFill(['friend_reward_text' => '$10 off'])->save();
        $this->assertArrayHasKey('top_referrer_recognition', $this->items($textlessBiz->fresh()));
    }

    public function test_the_card_action_creates_a_locked_draft_and_lands_on_confirm(): void
    {
        [$business, $leader] = $this->cardBusiness();
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->post(route('campaigns.recognition'), ['standings_month' => '2026-10']);
        $draft = SmsCampaign::query()->where('provenance', SmsCampaign::PROVENANCE_TOP_REFERRER)->firstOrFail();
        $response->assertRedirect(route('campaigns.confirm', $draft));
        $this->assertSame($leader->id, $draft->recipient_membership_id);
        $this->assertSame('2026-10', $draft->standings_month);
        $this->assertSame(4, $draft->standings_count);
        $this->assertSame('Thank you, Sam! You were our top referrer in October: 4 friends you sent us came in, more than anyone else.', $draft->body);
        // The share link is NEVER stored body text.
        $this->assertStringNotContainsString('Your link:', $draft->body);
        $this->assertStringNotContainsString($leader->plainShareToken(), $draft->body);
    }

    public function test_the_confirm_is_scoped_to_one_customer_with_the_rendered_link(): void
    {
        [$business, $leader] = $this->cardBusiness();
        $draft = $this->draftFor($business);
        $owner = World::owner($business);

        $html = $this->actingAs($owner)->get(route('campaigns.confirm', $draft))->assertOk()->getContent();
        $this->assertStringContainsString('Sending to: Sam Reed (1 customer)', $html);
        $this->assertStringContainsString('Send to 1 customer', $html);
        $this->assertStringContainsString('Total $0.04 for 1 customer. Once sent, this text cannot be unsent.', $html);
        $this->assertStringContainsString('Your link: '.route('refer.friend', ['token' => $leader->plainShareToken()]), $html);
    }

    public function test_the_send_delivers_one_text_metered_and_redacted_in_evidence(): void
    {
        [$business, $leader] = $this->cardBusiness();
        $draft = $this->draftFor($business);
        $owner = World::owner($business);
        $this->actingAs($owner)->get(route('campaigns.confirm', $draft));

        $this->actingAs($owner)->post(route('campaigns.send', $draft))->assertRedirect(route('campaigns.show', $draft));

        $rows = SmsCampaignRecipient::query()->where('campaign_id', $draft->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($leader->id, $rows[0]->membership_id);
        $this->assertSame(4, $rows[0]->amount_cents, '2 segments at $0.02 (the link costs one extra segment)');
        $this->assertCount(1, $this->gateway->sent);
        $sentBody = $this->gateway->sent[0]['body'] ?? $this->gateway->sent[0]->body ?? null;
        $this->assertStringContainsString('Your link: '.route('refer.friend', ['token' => $leader->plainShareToken()]), (string) $sentBody);
        $this->assertStringContainsString('Reply STOP to opt out.', (string) $sentBody);

        // Evidence stores the token redacted; the detail page
        // renders that copy, never a live token.
        $evidence = SmsMessage::query()->find($rows[0]->sms_message_id);
        $this->assertStringNotContainsString($leader->plainShareToken(), $evidence->rendered_body);
        $this->assertStringContainsString('[redacted]', $evidence->rendered_body);
        $detail = $this->actingAs($owner)->get(route('campaigns.show', $draft))->assertOk()->getContent();
        $this->assertStringNotContainsString($leader->plainShareToken(), $detail);
        $this->assertStringContainsString('Top referrer thank-you', $detail);

        // Consumed: the card no longer offers this pair.
        $this->assertArrayNotHasKey('top_referrer_recognition', $this->items($business->fresh()));
    }

    public function test_the_generic_composer_and_duplicate_can_never_open_a_recognition_draft(): void
    {
        [$business] = $this->cardBusiness();
        $draft = $this->draftFor($business);
        $owner = World::owner($business);

        // Composer open redirects to the scoped editor.
        $this->actingAs($owner)->get(route('campaigns.create', ['draft' => $draft->id]))
            ->assertRedirect(route('campaigns.recognition.edit', $draft));

        // The generic store cannot write its body either.
        $this->actingAs($owner)->post(route('campaigns.store'), ['draft_id' => $draft->id, 'body' => 'Blast everyone'])
            ->assertRedirect(route('campaigns.recognition.edit', $draft));
        $this->assertStringNotContainsString('Blast everyone', $draft->fresh()->body);

        // Index carries no duplicate action for it, and the
        // duplicate route itself refuses once sent: send first.
        $this->actingAs($owner)->get(route('campaigns.confirm', $draft));
        $this->actingAs($owner)->post(route('campaigns.send', $draft));
        $index = $this->actingAs($owner)->get(route('campaigns.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('campaigns.duplicate', $draft), $index);
        $count = SmsCampaign::count();
        $this->actingAs($owner)->post(route('campaigns.duplicate', $draft));
        $this->assertSame($count, SmsCampaign::count(), 'duplicate created nothing');
    }

    public function test_a_moved_count_regenerates_an_uncustomised_body_at_confirm(): void
    {
        [$business, $leader] = $this->cardBusiness();
        $draft = $this->draftFor($business);
        $owner = World::owner($business);
        // A held October visit releases: the count moves 4 -> 5.
        $held = $this->visit($leader, ['coverage' => 'held', 'reward' => ['hold' => true]]);
        $held->billingEvent->forceFill(['coverage' => 'billable'])->save();
        $held->reward->forceFill(['hold' => false])->save();

        $html = $this->actingAs($owner)->get(route('campaigns.confirm', $draft))->assertOk()->getContent();
        $this->assertStringContainsString('The standings changed since you last saw them. The text above uses the updated count.', $html);
        $this->assertStringContainsString('5 friends you sent us came in', $html);
        $this->assertSame(5, $draft->fresh()->standings_count);
    }

    public function test_a_customised_body_with_moved_standings_cannot_send_until_reconfirmed(): void
    {
        [$business, $leader] = $this->cardBusiness();
        $draft = $this->draftFor($business);
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('campaigns.recognition.update', $draft), [
            'body' => 'Thank you, Sam! You were our top referrer in October: 4 friends you sent us came in, more than anyone else. Show this text for $5 off.',
        ]);
        $this->assertTrue($draft->fresh()->body_customized);

        $held = $this->visit($leader, ['coverage' => 'held', 'reward' => ['hold' => true]]);
        $held->billingEvent->forceFill(['coverage' => 'billable'])->save();
        $held->reward->forceFill(['hold' => false])->save();

        $html = $this->actingAs($owner)->get(route('campaigns.confirm', $draft))->assertOk()->getContent();
        $this->assertStringContainsString('The standings changed: the count is now 5. Update your text, then send again.', $html);
        $this->assertStringNotContainsString('Send to 1 customer</button>', $html);

        $this->actingAs($owner)->post(route('campaigns.send', $draft));
        $this->assertCount(0, $this->gateway->sent, 'a customised body never sends on moved standings');
        $this->assertSame(0, SmsCampaignRecipient::query()->where('campaign_id', $draft->id)->count());

        // Re-confirm with the updated text, then it sends.
        $this->actingAs($owner)->post(route('campaigns.recognition.update', $draft), ['use_updated' => 1]);
        $this->assertFalse($draft->fresh()->body_customized);
        $this->actingAs($owner)->get(route('campaigns.confirm', $draft));
        $this->actingAs($owner)->post(route('campaigns.send', $draft));
        $this->assertCount(1, $this->gateway->sent);
    }

    public function test_a_send_below_the_floor_or_with_a_lost_leader_refuses_outright(): void
    {
        [$business, $leader] = $this->cardBusiness();
        $draft = $this->draftFor($business);
        $owner = World::owner($business);
        $this->actingAs($owner)->get(route('campaigns.confirm', $draft));

        // Two of the four visits are denied after confirm: the
        // leader drops below the floor before send.
        Referral::query()->where('referrer_membership_id', $leader->id)->limit(2)->get()
            ->each(function ($referral) {
                $referral->billingEvent->forceFill(['coverage' => 'fraud_denied'])->save();
                $referral->reward->forceFill(['state' => 'denied'])->save();
            });

        $this->actingAs($owner)->post(route('campaigns.send', $draft));
        $this->assertCount(0, $this->gateway->sent);
        $this->assertSame(SmsCampaign::STATUS_DRAFT, $draft->fresh()->status);
        $this->assertSame(0, SmsMessage::count());
    }

    public function test_a_draft_confirmed_after_day_14_refuses_as_stale(): void
    {
        // A draft whose standings month is no longer the
        // current window month is the day-15 state, reached
        // without time travel (the app's idle-session timeout
        // makes a 10-day jump untestable in one session; the
        // branch reads only the stamped month vs the current
        // window month).
        [$business, $leader] = $this->cardBusiness();
        $owner = World::owner($business);
        $draft = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => 'Thank you, Sam! You were our top referrer in September: 4 friends you sent us came in, more than anyone else.',
            'provenance' => SmsCampaign::PROVENANCE_TOP_REFERRER,
            'recipient_membership_id' => $leader->id,
            'standings_month' => '2026-09',
            'standings_count' => 4,
            'standings_form' => 'sole',
            'origin' => 'top_referrer_prompt',
            'status' => SmsCampaign::STATUS_DRAFT,
            'created_by' => $owner->id,
        ]);

        $html = $this->actingAs($owner)->get(route('campaigns.confirm', $draft))->assertOk()->getContent();
        $this->assertStringContainsString('This thank-you was for September, and that month has closed. It was not sent.', $html);
        $this->assertStringNotContainsString('Send to 1 customer</button>', $html);

        $this->actingAs($owner)->post(route('campaigns.send', $draft));
        $this->assertCount(0, $this->gateway->sent);
        $this->assertSame(SmsCampaign::STATUS_DRAFT, $draft->fresh()->status);
    }

    public function test_f1_can_delay_the_recognition_past_the_window_and_the_month_is_skipped(): void
    {
        [$business, $leader] = $this->cardBusiness();
        // The leader already has 4 campaign touches in 30 days:
        // F1's volume limb binds, with a return after day 14.
        for ($i = 0; $i < 4; $i++) {
            $other = SmsCampaign::create([
                'business_id' => $business->id, 'body' => 'Hello',
                'status' => SmsCampaign::STATUS_SENT, 'sent_at' => now()->subDays(2),
                'created_by' => World::owner($business)->id,
            ]);
            SmsCampaignRecipient::create([
                'campaign_id' => $other->id, 'membership_id' => $leader->id,
                'customer_id' => $leader->customer_id, 'phone_e164' => $leader->customer->phone_e164,
                'delivery_state' => SmsCampaignRecipient::STATE_DELIVERED,
                'segments' => 1, 'amount_cents' => 2,
                'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
            ]);
        }

        // No card either: the leader is not sendable, so nobody
        // is crowned (the F1 limb of the audience read).
        $this->assertArrayNotHasKey('top_referrer_recognition', $this->items($business->fresh()));
    }

    public function test_f1_spacing_on_a_draft_takes_the_f1z_branch_on_confirm(): void
    {
        [$business, $leader] = $this->cardBusiness();
        $draft = $this->draftFor($business);
        $owner = World::owner($business);

        // A campaign touch lands yesterday: spacing now binds.
        // (The touch is dated by forceFill after creation —
        // created_at is not fillable, so the create-array form
        // silently dated it "now" and the Nov 16 return date
        // asserted below could never have been observed.)
        $other = SmsCampaign::create([
            'business_id' => $business->id, 'body' => 'Hello',
            'status' => SmsCampaign::STATUS_SENT, 'sent_at' => now()->subDay(),
            'created_by' => World::owner($business)->id,
        ]);
        $touchRow = SmsCampaignRecipient::create([
            'campaign_id' => $other->id, 'membership_id' => $leader->id,
            'customer_id' => $leader->customer_id, 'phone_e164' => $leader->customer->phone_e164,
            'delivery_state' => SmsCampaignRecipient::STATE_DELIVERED,
            'segments' => 1, 'amount_cents' => 2,
        ]);
        $touchRow->forceFill(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()])->save();

        $html = $this->actingAs($owner)->get(route('campaigns.confirm', $draft))->assertOk()->getContent();
        // Two-key Condition 2 (2026-10-28): the recognition
        // confirm's F1-Z branch is leader-scoped in cause,
        // date, and wording. The whole-audience "Every
        // customer who agreed to texts..." sentence never
        // renders on this surface, and the date is the
        // leader's OWN earliest return (the Nov 9 touch +
        // 7 days = Nov 16), not a business-wide minimum.
        $this->assertStringContainsString('Total $0.00 for 0 customers. This customer got a campaign text too recently.', $html);
        $this->assertStringContainsString('This draft stays saved. This customer can get texts again starting Nov 16, 2026.', $html);
        $this->assertStringNotContainsString('Every customer who agreed to texts', $html);
        $this->assertStringNotContainsString('Send to 1 customer</button>', $html);

        $this->actingAs($owner)->post(route('campaigns.send', $draft));
        $this->assertCount(0, $this->gateway->sent, 'no override exists for F1');
    }

    public function test_stop_at_send_flashes_tr09d_not_the_recency_sentence(): void
    {
        // Condition 2 pin (i): a STOP landing between confirm
        // and send is a non-recency ineligibility, so the send
        // outcome takes TR-09d's flash verbatim — the recency
        // sentence must not be the flash for a STOPped leader.
        [$business, $leader] = $this->cardBusiness();
        $draft = $this->draftFor($business);
        $owner = World::owner($business);
        $this->actingAs($owner)->get(route('campaigns.confirm', $draft));

        \App\Models\SmsOptOut::create(['phone_e164' => $leader->customer->phone_e164, 'opted_out_at' => now(), 'source' => 'carrier_stop']);

        $response = $this->actingAs($owner)->post(route('campaigns.send', $draft));
        $response->assertSessionHas('status', 'This customer cannot receive texts right now, so the thank-you was not sent.');
        $this->assertCount(0, $this->gateway->sent);
        $this->assertSame(0, SmsMessage::count());
        $this->assertSame(SmsCampaign::STATUS_DRAFT, $draft->fresh()->status);
    }

    public function test_f1_at_send_flashes_recency_and_the_confirm_names_the_leaders_own_return_date(): void
    {
        // Condition 2 pin (ii): the leader is genuinely
        // recency-skipped at send, so the outcome IS
        // recognition_recent — and the confirm's F1-Z branch
        // shows the LEADER'S OWN earliest return. An unrelated
        // member texted 3 days ago has an earlier return
        // (Nov 14); a business-wide minimum would name that
        // date and the whole-audience sentence.
        [$business, $leader] = $this->cardBusiness();
        $draft = $this->draftFor($business);
        $owner = World::owner($business);

        $other = $this->member($business, 'Uma');
        $this->touch($other, now()->subDays(3));
        $this->touch($leader, now()->subDay());

        $response = $this->actingAs($owner)->post(route('campaigns.send', $draft));
        $response->assertSessionHas('status', 'This customer got a campaign text too recently, so the thank-you was not sent.');
        $this->assertCount(0, $this->gateway->sent, 'no override exists for F1');
        $this->assertSame(0, SmsMessage::count());

        $html = $this->actingAs($owner)->get(route('campaigns.confirm', $draft))->assertOk()->getContent();
        $this->assertStringContainsString('Total $0.00 for 0 customers. This customer got a campaign text too recently.', $html);
        $this->assertStringContainsString('This draft stays saved. This customer can get texts again starting Nov 16, 2026.', $html);
        $this->assertStringNotContainsString('Nov 14, 2026', $html, "another customer's earlier return date must not render on this customer's confirm");
        $this->assertStringNotContainsString('Every customer who agreed to texts', $html);
        $this->assertStringNotContainsString('Send to 1 customer</button>', $html);
    }

    public function test_a_stopped_leader_with_an_unrelated_recency_exclusion_renders_tr09d_not_f1z(): void
    {
        // Condition 2 pin (iii): the leader is STOPped while
        // an unrelated member is recency-excluded. The
        // confirm must render the TR-09d branch for THIS
        // customer — the F1-Z branch keys on the leader's
        // own recency skip, never on business-wide totals.
        [$business, $leader] = $this->cardBusiness();
        $draft = $this->draftFor($business);
        $owner = World::owner($business);

        $other = $this->member($business, 'Uma');
        $this->touch($other, now()->subDay());
        \App\Models\SmsOptOut::create(['phone_e164' => $leader->customer->phone_e164, 'opted_out_at' => now(), 'source' => 'carrier_stop']);

        $html = $this->actingAs($owner)->get(route('campaigns.confirm', $draft))->assertOk()->getContent();
        $this->assertStringContainsString('This customer cannot receive texts right now, so the thank-you was not sent.', $html);
        $this->assertStringNotContainsString('Every customer who agreed to texts', $html);
        $this->assertStringNotContainsString('got a campaign text too recently', $html);
        $this->assertStringNotContainsString('Send to 1 customer</button>', $html);
    }

    public function test_a_tie_card_reoffers_the_remaining_co_top_with_tie_wording_after_a_partial_send(): void
    {
        // Condition 3 pin: the card line branches on the
        // STANDINGS FORM, not the unsent count. A tie of 2
        // with one co-top already sent re-offers the
        // remaining co-top with TR-02's tie wording scoped
        // to the one unsent name — TR-01's sole form renders
        // nowhere on that card.
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-11-01 09:00:00', 'America/New_York'));
        $business = World::business();
        $this->succeededCard($business);
        $a = $this->member($business, 'Ann');
        $b = $this->member($business, 'Bob');
        $this->visits($a, 3);
        $this->visits($b, 3);
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('campaigns.recognition'), ['standings_month' => '2026-10']);
        $annDraft = SmsCampaign::query()->where('provenance', SmsCampaign::PROVENANCE_TOP_REFERRER)
            ->where('recipient_membership_id', $a->id)->firstOrFail();
        $bobDraft = SmsCampaign::query()->where('provenance', SmsCampaign::PROVENANCE_TOP_REFERRER)
            ->where('recipient_membership_id', $b->id)->firstOrFail();
        // The remaining co-top's draft body is the tie template.
        $this->assertStringContainsString('You were one of our top referrers in October: 3 friends you sent us came in, tied for the most.', $bobDraft->body);

        $this->actingAs($owner)->get(route('campaigns.confirm', $annDraft));
        $this->actingAs($owner)->post(route('campaigns.send', $annDraft));
        $this->assertCount(1, $this->gateway->sent);

        // Eight days on: the F1 spacing from Ann's own text
        // has cleared and the window is still open (day 9).
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-11-09 09:00:00', 'America/New_York'));
        $items = $this->items($business->fresh());
        $this->assertArrayHasKey('top_referrer_recognition', $items);
        $this->assertSame('Your top referrers in October: Bob Reed, with 3 friends who visited each.', $items['top_referrer_recognition']['line']);
        $this->assertSame('Send thank-you texts', $items['top_referrer_recognition']['action_label']);
        $this->assertStringNotContainsString('Your top referrer in October: Bob Reed, with 3 friends who visited.', $items['top_referrer_recognition']['line']);
        $this->assertStringNotContainsString('Ann Reed', $items['top_referrer_recognition']['line']);
    }

    public function test_a_tie_creates_one_draft_per_co_top_with_tie_wording(): void
    {
        $business = World::business();
        $this->succeededCard($business);
        $a = $this->member($business, 'Ann');
        $b = $this->member($business, 'Bob');
        $this->visits($a, 3);
        $this->visits($b, 3);
        $owner = World::owner($business);

        $items = $this->items($business);
        $this->assertStringContainsString('Your top referrers in October: Ann Reed and Bob Reed, with 3 friends who visited each.', $items['top_referrer_recognition']['line']);

        $this->actingAs($owner)->post(route('campaigns.recognition'), ['standings_month' => '2026-10']);
        $drafts = SmsCampaign::query()->where('provenance', SmsCampaign::PROVENANCE_TOP_REFERRER)->orderBy('recipient_membership_id')->get();
        $this->assertCount(2, $drafts);
        foreach ($drafts as $draft) {
            $this->assertStringContainsString('You were one of our top referrers in October: 3 friends you sent us came in, tied for the most.', $draft->body);
            $this->assertStringNotContainsString('more than anyone else', $draft->body);
        }
        // Neither text names the other co-top.
        $this->assertStringNotContainsString('Bob', $drafts->firstWhere('recipient_membership_id', $a->id)->body);
        $this->assertStringNotContainsString('Ann', $drafts->firstWhere('recipient_membership_id', $b->id)->body);
    }

    public function test_post_send_denials_trigger_no_correction_text(): void
    {
        [$business, $leader] = $this->cardBusiness();
        $draft = $this->draftFor($business);
        $owner = World::owner($business);
        $this->actingAs($owner)->get(route('campaigns.confirm', $draft));
        $this->actingAs($owner)->post(route('campaigns.send', $draft));
        $this->assertCount(1, $this->gateway->sent);

        // A counted visit is denied after the send.
        $referral = Referral::query()->where('referrer_membership_id', $leader->id)->first();
        $referral->billingEvent->forceFill(['coverage' => 'fraud_denied'])->save();
        $referral->reward->forceFill(['state' => 'denied', 'hold' => false])->save();

        // Reading every surface again sends nothing more.
        $this->items($business->fresh());
        $this->actingAs($owner)->get(route('campaigns.show', $draft));
        $this->assertCount(1, $this->gateway->sent, 'no correction text is ever sent');
        $this->assertSame(1, SmsCampaign::query()->where('provenance', SmsCampaign::PROVENANCE_TOP_REFERRER)->count());
    }

    public function test_a_stop_landing_between_confirm_and_job_skips_unmetered(): void
    {
        [$business, $leader] = $this->cardBusiness();
        $draft = $this->draftFor($business);
        $owner = World::owner($business);
        $this->actingAs($owner)->get(route('campaigns.confirm', $draft));

        // STOP lands after confirm but the send's audience
        // re-derivation removes the leader before any row: the
        // send refuses as ineligible and meters nothing.
        \App\Models\SmsOptOut::create(['phone_e164' => $leader->customer->phone_e164, 'opted_out_at' => now(), 'source' => 'carrier_stop']);
        $this->actingAs($owner)->post(route('campaigns.send', $draft));
        $this->assertCount(0, $this->gateway->sent);
        $this->assertSame(0, SmsMessage::count());
    }
}
