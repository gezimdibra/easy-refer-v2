<?php

namespace Tests\Feature\Coupons;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\CouponBatch;
use App\Models\CouponClaim;
use App\Models\Membership;
use App\Models\PaymentMethod;
use App\Models\Referral;
use App\Models\Reward;
use App\Services\Coupons\CouponService;
use App\Support\OpaqueToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * ID-11 direct coupons (S3, Amendment 53; design pass
 * idea-11-design-pass.md). The pins are both-directions: the
 * coupon path's presences (immediate unlock, per-batch counts,
 * counter redemption) AND its absences (no BillingEvent, no
 * free-slot or cap consumption, no Referral, no membership or
 * share link, no Reward, no hold state anywhere).
 */
class CouponTest extends TestCase
{
    use RefreshDatabase;

    private FakePhoneVerificationGateway $verify;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verify = new FakePhoneVerificationGateway;
        $this->app->instance(PhoneVerificationGateway::class, $this->verify);
        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        Mail::fake();
    }

    private function carryFlowCookie(\Illuminate\Testing\TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'er2_flow') {
                $this->withUnencryptedCookie('er2_flow', $cookie->getValue());
            }
        }
    }

    private function makeBatch(Business $business, array $overrides = []): CouponBatch
    {
        $token = OpaqueToken::generate();

        return CouponBatch::create(array_merge([
            'business_id' => $business->id,
            'label' => 'Gym',
            'offer_text' => '$5 off your first visit',
            'claim_cap' => 100,
            'token_hash' => OpaqueToken::hash($token),
            'token_encrypted' => $token,
            'status' => CouponBatch::STATUS_ACTIVE,
            'expires_at' => now()->addDays(30),
        ], $overrides));
    }

    /** Drive the full customer claim flow over HTTP; returns the claim. */
    private function claimThroughHttp(CouponBatch $batch, string $phone = '+12125550111', string $first = 'Pat'): CouponClaim
    {
        $token = $batch->plainToken();

        $this->carryFlowCookie($this->get(route('coupon.landing', ['token' => $token]))->assertOk());

        $this->carryFlowCookie($this->post(route('coupon.start', ['token' => $token]), [
            'first_name' => $first,
            'last_name' => 'Lee',
            'phone' => $phone,
            'consent' => '1',
        ])->assertRedirect(route('refer.verify')));

        $response = $this->post(route('refer.verify.check'), ['code' => '123456']);
        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertNotNull($location);
        $claimToken = basename((string) parse_url($location, PHP_URL_PATH));

        return CouponClaim::byToken($claimToken);
    }

    private int $participantSequence = 7000;

    /** Give the business a succeeded default card on file. */
    private function addCard(Business $business): PaymentMethod
    {
        $method = PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'sq_card_coupon_'.$business->id,
            'card_brand' => 'VISA',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_coupon_'.$business->id.'_'.uniqid(),
        ]);
        $business->forceFill(['billing_status' => 'active'])->save();

        return $method;
    }

    /** Spend the business's whole first-5 allowance with participant billing events. */
    private function exhaustAllowance(Business $business): void
    {
        for ($i = 0; $i < 5; $i++) {
            $n = $this->participantSequence++;
            $referrer = World::customer('+1212555'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'Reg', 'Ular');
            [$membership] = World::membership($business, $referrer);
            $friend = World::customer('+1212555'.str_pad((string) ($n + 500), 4, '0', STR_PAD_LEFT), 'Fri', 'End');
            [$referral] = World::referral($membership, $friend);
            BillingEvent::create([
                'business_id' => $business->id,
                'referral_id' => $referral->id,
                'amount_cents' => 200,
                'coverage' => 'free_credit',
            ]);
        }
    }

    private function staffNonce(string $token): string
    {
        $response = $this->get(route('redeem.show', ['token' => $token]))->assertOk();
        preg_match('/name="nonce" value="([^"]+)"/', $response->getContent(), $m);
        $this->assertNotEmpty($m, 'staff screen carries a nonce');

        return $m[1];
    }

    // ---------------------------------------------------------------
    // Schema: the absences are the design (pass Sections 2.3, 5.1)
    // ---------------------------------------------------------------

    public function test_claim_schema_has_no_referrer_billing_hold_or_vesting_columns(): void
    {
        foreach (['referrer_membership_id', 'referrer_id', 'billing_event_id', 'hold', 'hold_rules', 'held_at', 'risk_score', 'available_at', 'vesting_days'] as $column) {
            $this->assertFalse(Schema::hasColumn('coupon_claims', $column), "coupon_claims must not carry {$column}");
        }

        foreach (['scans_count', 'claims_count', 'redemptions_count', 'claims_minted'] as $column) {
            $this->assertFalse(Schema::hasColumn('coupon_batches', $column), "coupon_batches must not carry a stored counter {$column}");
        }

        $this->assertTrue(Schema::hasColumn('coupon_claims', 'source'));
        $this->assertTrue(Schema::hasColumn('coupon_claims', 'batch_id'));
    }

    // ---------------------------------------------------------------
    // couponFlowActive is a distinct predicate (pass Section 2.4)
    // ---------------------------------------------------------------

    public function test_coupon_flow_active_does_not_require_friend_reward_text(): void
    {
        $business = World::business(['friend_reward_text' => null, 'referrer_reward_text' => null]);

        $this->assertFalse($business->flowIsActive());
        $this->assertTrue($business->couponFlowActive());

        $closed = World::business(['status' => 'closed', 'closed_at' => now()]);
        $this->assertFalse($closed->couponFlowActive());
    }

    /**
     * The couponFlowActive truth table (business states x the
     * predicate), on the post-activation-redesign legs: it is
     * flowIsActive minus the friend reward text leg, nothing
     * else. The pass's pre-redesign billing leg (card required)
     * was superseded by the owner ruling of 2026-10-30: a
     * no-card business is live while its free allowance lasts.
     */
    public function test_coupon_flow_active_truth_table(): void
    {
        // Active, card on file, allowance intact: live.
        $withCard = World::business();
        $this->addCard($withCard);
        $this->assertTrue($withCard->fresh()->couponFlowActive());

        // Active, NO card, allowance left (the Step 14 shape):
        // live, exactly as the referral code is live.
        $cardless = World::business(['billing_status' => 'none']);
        $this->assertTrue($cardless->couponFlowActive());
        $this->assertNull($cardless->couponFlowBlockReason());

        // No card and the allowance spent: paused until a card.
        $spent = World::business(['billing_status' => 'none']);
        $this->exhaustAllowance($spent);
        $this->assertFalse($spent->fresh()->couponFlowActive());

        // Allowance spent but a card on file: live again.
        $this->addCard($spent);
        $this->assertTrue($spent->fresh()->couponFlowActive());

        // Past due INSIDE grace, card on file: live.
        $inGrace = World::business(['billing_status' => 'past_due']);
        $this->addCard($inGrace);
        $inGrace->forceFill(['billing_status' => 'past_due'])->save();
        \App\Models\AuditLog::write('billing.past_due', null, ['square_payment_id' => 'sq_coupon_grace'], $inGrace->fresh(), null);
        $this->assertTrue($inGrace->fresh()->couponFlowActive());

        // Past due PAST grace: paused, card or not.
        $pastGrace = World::business(['billing_status' => 'past_due']);
        $this->addCard($pastGrace);
        $pastGrace->forceFill(['billing_status' => 'past_due'])->save();
        \App\Models\AuditLog::write('billing.past_due', null, ['square_payment_id' => 'sq_coupon_paused'], $pastGrace->fresh(), null);
        \App\Models\AuditLog::query()->where('business_id', $pastGrace->id)->where('action', 'billing.past_due')
            ->update(['created_at' => now()->subDays(8)]);
        $this->assertFalse($pastGrace->fresh()->couponFlowActive());
        $this->assertSame('dunning_paused', $pastGrace->fresh()->couponFlowBlockReason());

        // Past due with no datable episode fails closed.
        $undated = World::business(['billing_status' => 'past_due']);
        $this->assertFalse($undated->couponFlowActive());

        // Not active, and closed: never live.
        $this->assertFalse(World::business(['status' => 'disabled'])->couponFlowActive());
        $closed = World::business(['status' => 'closed', 'closed_at' => now()]);
        $this->assertFalse($closed->couponFlowActive());
        $this->assertSame('closed', $closed->couponFlowBlockReason());

        // The demo business is never a live coupon flow.
        $demo = World::business(['is_demo' => true]);
        $this->assertFalse($demo->couponFlowActive());
    }

    public function test_coupon_claim_cannot_be_held(): void
    {
        // The claim's whole state set is the four shipped
        // states; there is no held state to reach, by type.
        $states = [
            CouponClaim::STATE_READY,
            CouponClaim::STATE_REDEEMED,
            CouponClaim::STATE_EXPIRED,
            CouponClaim::STATE_VOIDED,
        ];
        $this->assertSame(['ready', 'redeemed', 'expired', 'voided'], $states);
        $this->assertFalse(defined(CouponClaim::class.'::STATE_HELD'));
    }

    // ---------------------------------------------------------------
    // Owner: hint, tabs, creation budget shape
    // ---------------------------------------------------------------

    public function test_qr_page_carries_tabs_and_hint_dashboard_carries_neither(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $html = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();
        $this->assertStringContainsString('Referral codes', $html);
        $this->assertStringContainsString('Coupons', $html);
        $this->assertStringContainsString('Try Coupons: put your offer on paper around town and bring new people to your door.', $html);
        $this->assertSame(1, substr_count($html, 'Try Coupons:'), 'at most one hint renders on the surface');

        $dashboard = $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Try Coupons:', $dashboard);

        // Dismissal silences the hint for good.
        $this->actingAs($owner)->post(route('owner.qr.coupons.hint.dismiss'))->assertRedirect(route('owner.qr'));
        $html = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Try Coupons:', $html);
    }

    public function test_coupons_tab_aha_block_and_prefill_sources(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $html = $this->actingAs($owner)->get(route('owner.qr.coupons'))->assertOk()->getContent();
        $this->assertStringContainsString('Offers you print and place outside your shop, for people who have not found you yet.', $html);
        $this->assertStringContainsString('A referral needs a customer to share it. A coupon does not.', $html);
        $this->assertStringContainsString('That is the whole setup.', $html);
        // Prefill source 1: the business's own friend reward text.
        $this->assertStringContainsString('We filled this in from your current first-visit offer.', $html);
        $this->assertStringContainsString('value="$10 off your first visit"', $html);
        $this->assertStringContainsString('Coupon claims are never charged. You only pay when a referred customer walks in and redeems.', $html);

        // Prefill source 2: no friend text, the starter pack for the trade.
        $cafe = World::business(['friend_reward_text' => null, 'business_type' => 'cafe']);
        $html = $this->actingAs(World::owner($cafe))->get(route('owner.qr.coupons'))->assertOk()->getContent();
        $this->assertStringContainsString('We filled this in from the starter offer for shops like yours.', $html);
        $this->assertStringContainsString('A free regular coffee', $html);
    }

    public function test_create_batch_from_tab_with_defaults(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $freeBefore = $business->free_visits_remaining;

        $response = $this->actingAs($owner)->post(route('owner.qr.coupons.store'), [
            'offer_text' => '$10 off your first visit',
            'label_chip' => 'Gym',
            'claim_cap' => 100,
            'expiry_days' => 30,
            'prefill_source' => 'owner',
            'created_from' => 'tab',
        ]);

        $batch = CouponBatch::query()->where('business_id', $business->id)->firstOrFail();
        $response->assertRedirect(route('owner.qr.coupons.done', ['batch' => $batch]));
        $this->assertSame('Gym', $batch->label);
        $this->assertSame(100, $batch->claim_cap);
        $this->assertTrue($batch->expires_at->between(now()->addDays(29), now()->addDays(31)));

        $done = $this->actingAs($owner)->get(route('owner.qr.coupons.done', ['batch' => $batch]))->assertOk()->getContent();
        $this->assertStringContainsString('Your batch is live.', $done);
        $this->assertStringContainsString('These go outside, not on your counter.', $done);
        $this->assertStringContainsString('Print the flyer sheet', $done);

        // The hint is gone once a batch exists.
        $qr = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Try Coupons:', $qr);

        // Creation touched no money state.
        $this->assertSame($freeBefore, $business->fresh()->free_visits_remaining);
        $this->assertSame(0, BillingEvent::count());
    }

    public function test_create_batch_requires_a_label_and_valid_steps(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('owner.qr.coupons.store'), [
            'offer_text' => '$5 off',
            'claim_cap' => 100,
            'expiry_days' => 30,
        ])->assertSessionHasErrors('label_chip');

        $this->actingAs($owner)->post(route('owner.qr.coupons.store'), [
            'offer_text' => '$5 off',
            'label_chip' => 'Gym',
            'claim_cap' => 999,
            'expiry_days' => 30,
        ])->assertSessionHasErrors('claim_cap');

        $this->assertSame(0, CouponBatch::count());
    }

    public function test_create_batch_behind_a_paused_door_saves_the_form_and_creates_nothing(): void
    {
        // Paused on the current legs: no card and the free
        // allowance spent (the 2026-10-30 activation shape).
        $business = World::business(['billing_status' => 'none']);
        $this->exhaustAllowance($business);
        $this->assertFalse($business->fresh()->couponFlowActive());
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('owner.qr.coupons.store'), [
            'offer_text' => '$5 off your first visit',
            'label_chip' => 'Gym',
            'claim_cap' => 100,
            'expiry_days' => 30,
        ])->assertSessionHas('coupon_activation_notice');

        $this->assertSame(0, CouponBatch::count());
    }

    public function test_coupons_tab_works_for_a_textless_business(): void
    {
        // The F-2 shape coupons must not inherit: no friend text,
        // card on file. The batch's own snapshot is its text.
        $business = World::business(['friend_reward_text' => null, 'referrer_reward_text' => null]);
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('owner.qr.coupons.store'), [
            'offer_text' => 'A free regular coffee',
            'label_chip' => 'Laundromat',
            'claim_cap' => 50,
            'expiry_days' => 14,
            'prefill_source' => 'pack',
        ])->assertRedirect();

        $this->assertSame(1, CouponBatch::query()->where('business_id', $business->id)->count());
    }

    public function test_batch_actions_are_tenant_scoped(): void
    {
        $business = World::business();
        $other = World::business();
        $batch = $this->makeBatch($business);
        $stranger = World::owner($other);

        $this->actingAs($stranger)->get(route('owner.qr.coupons.done', ['batch' => $batch]))->assertNotFound();
        $this->actingAs($stranger)->get(route('owner.qr.coupons.claims', ['batch' => $batch]))->assertNotFound();
        $this->actingAs($stranger)->get(route('owner.qr.coupons.flyer', ['batch' => $batch]))->assertNotFound();
        $this->actingAs($stranger)->post(route('owner.qr.coupons.kill', ['batch' => $batch]))->assertNotFound();
        $this->assertSame(CouponBatch::STATUS_ACTIVE, $batch->fresh()->status);
    }

    // ---------------------------------------------------------------
    // Customer flow: immediate unlock, absences pinned
    // ---------------------------------------------------------------

    public function test_full_claim_flow_unlocks_immediately_and_bills_nothing(): void
    {
        $business = World::business();
        $batch = $this->makeBatch($business);
        $freeBefore = $business->free_visits_remaining;

        $claim = $this->claimThroughHttp($batch);

        $this->assertSame(CouponClaim::STATE_READY, $claim->state);
        $this->assertSame('coupon', $claim->source);
        $this->assertSame($batch->id, $claim->batch_id);
        $this->assertSame('$5 off your first visit', $claim->offer_text);
        $this->assertTrue($claim->expires_at->between(now()->addDays(29), now()->addDays(31)));

        // The absences, asserted directly (pass Section 3.2):
        $this->assertSame(0, BillingEvent::count(), 'a coupon claim must never create a BillingEvent');
        $this->assertSame($freeBefore, $business->fresh()->free_visits_remaining, 'a coupon claim must not consume a free visit');
        $this->assertSame(0, Referral::count(), 'a coupon claim must not create a referral');
        $this->assertSame(0, Reward::count(), 'a coupon claim must not mint a reward');
        $this->assertSame(0, Membership::count(), 'a coupon claim must not create a membership or share link');

        // The ready screen: the coupon, its date, and no share prompt.
        $ready = $this->get(route('coupon.claim.page', ['token' => $claim->plainToken()]))->assertOk()->getContent();
        $this->assertStringContainsString('Your coupon is ready.', $ready);
        $this->assertStringContainsString('Show this screen', $ready);
    }

    /**
     * NO-BILL, both directions, on every money surface the
     * brief names: the claim completes AND the money does not
     * move (no BillingEvent, no free slot, no allowance, no
     * cap counter, nothing on Billing or the forecast).
     */
    public function test_coupon_redemption_moves_no_money_surface(): void
    {
        $business = World::business();
        $batch = $this->makeBatch($business);

        $forecastBefore = \App\Support\BillingForecast::gatedPreview($business->fresh());
        $allowanceBefore = $business->fresh()->allowanceUsed();
        $freeBefore = $business->fresh()->free_visits_remaining;

        $claim = $this->claimThroughHttp($batch, '+12125550111');
        $nonce = $this->staffNonce($claim->plainToken());
        $this->post(route('redeem.confirm', ['token' => $claim->plainToken()]), ['nonce' => $nonce])->assertRedirect();
        $this->assertSame(CouponClaim::STATE_REDEEMED, $claim->fresh()->state);

        $this->assertSame(0, BillingEvent::count(), 'coupon redemption must create zero BillingEvents');
        $this->assertSame($freeBefore, $business->fresh()->free_visits_remaining, 'coupon redemption must consume zero free slots');
        $this->assertSame($allowanceBefore, $business->fresh()->allowanceUsed(), 'coupon redemption must not move the allowance or cap walk');
        $this->assertSame($forecastBefore, \App\Support\BillingForecast::gatedPreview($business->fresh()), 'coupon redemption must not appear in the forecast');
        $this->assertFalse(\App\Support\BillingForecast::chargeIsExpected($business->fresh()), 'no charge may become expected from a coupon redemption');

        $owner = World::owner($business);
        $billing = $this->actingAs($owner)->get(route('settings.billing'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Gym', $billing, 'the coupon batch must not appear on the Billing page');
    }

    /** Mint-level refusals: cap+1, killed, and expired batches refuse at the mint gate too. */
    public function test_mint_gate_refuses_cap_plus_one_killed_and_expired(): void
    {
        $business = World::business();
        $service = app(CouponService::class);
        $input = fn (string $phone) => ['first_name' => 'Pat', 'last_name' => 'Lee', 'phone_e164' => $phone];

        $capped = $service->createBatch($business, null, 'Gym', '$5 off', 1, 30, 'tab', 'owner');
        $this->assertSame('minted', $service->mintClaim($capped, $input('+12125550111'))['kind']);
        $this->assertSame('full', $service->mintClaim($capped, $input('+12125550222'))['kind']);
        $this->assertSame(1, CouponClaim::query()->where('batch_id', $capped->id)->count());

        $killed = $service->createBatch($business, null, 'Board', '$5 off', 100, 30, 'tab', 'owner');
        $service->killBatch($killed);
        $this->assertSame('ended', $service->mintClaim($killed, $input('+12125550333'))['kind']);

        $expired = $this->makeBatch($business, ['label' => 'Old', 'expires_at' => now()->subDay()]);
        $this->assertSame('ended', $service->mintClaim($expired, $input('+12125550444'))['kind']);
        $this->assertSame(1, CouponClaim::count(), 'only the one capped mint may exist');
    }

    /** Replay: a redeemed coupon token can never redeem twice. */
    public function test_redemption_replay_is_refused_and_changes_nothing(): void
    {
        $business = World::business();
        $batch = $this->makeBatch($business);
        $claim = $this->claimThroughHttp($batch, '+12125550111');
        $service = app(CouponService::class);

        $first = $service->redeemClaim($claim, null);
        $this->assertSame('redeemed', $first['outcome']);
        $redeemedAt = $claim->fresh()->redeemed_at;

        $replay = $service->redeemClaim($claim->fresh(), null);
        $this->assertSame('already_redeemed', $replay['outcome']);
        $this->assertTrue($redeemedAt->equalTo($claim->fresh()->redeemed_at), 'replay must not restamp the redemption');
        $this->assertSame(1, \App\Models\ReferralEvent::query()->where('event_type', 'coupon_redeemed')->count());
    }

    public function test_verify_screen_carries_the_coupon_delta_line(): void
    {
        $business = World::business();
        $batch = $this->makeBatch($business);
        $token = $batch->plainToken();

        $this->carryFlowCookie($this->get(route('coupon.landing', ['token' => $token]))->assertOk());
        $this->carryFlowCookie($this->post(route('coupon.start', ['token' => $token]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '+12125550111', 'consent' => '1',
        ])->assertRedirect(route('refer.verify')));

        $html = $this->get(route('refer.verify'))->assertOk()->getContent();
        $this->assertStringContainsString('We will text you a code to unlock your coupon. This keeps it one per person.', $html);
        $this->assertStringContainsString('Step 2 of 2', $html);
    }

    public function test_scans_and_claims_count_per_batch(): void
    {
        $business = World::business();
        $gym = $this->makeBatch($business, ['label' => 'Gym']);
        $laundromat = $this->makeBatch($business, ['label' => 'Laundromat']);

        $this->claimThroughHttp($gym, '+12125550111');
        $this->get(route('coupon.landing', ['token' => $laundromat->plainToken()]))->assertOk();
        $this->get(route('coupon.landing', ['token' => $laundromat->plainToken()]))->assertOk();

        $service = app(CouponService::class);
        $gymFunnel = $service->batchFunnel($gym);
        $laundromatFunnel = $service->batchFunnel($laundromat);

        // The gym landing was opened once by the claim flow.
        $this->assertSame(1, $gymFunnel['scans']);
        $this->assertSame(1, $gymFunnel['claimed']);
        $this->assertSame(0, $gymFunnel['redeemed']);
        $this->assertSame(2, $laundromatFunnel['scans']);
        $this->assertSame(0, $laundromatFunnel['claimed']);
        $this->assertEqualsWithDelta(1.0, $gymFunnel['scan_to_claim'], 0.001);

        // The owner list reads the same derived numbers.
        $owner = World::owner($business);
        $html = $this->actingAs($owner)->get(route('owner.qr.coupons'))->assertOk()->getContent();
        $this->assertStringContainsString('Scans = people who opened the coupon.', $html);
        $this->assertStringContainsString('Scans 1 / Claimed 1 / Redeemed 0', $html);
        $this->assertStringContainsString('Scans 2 / Claimed 0 / Redeemed 0', $html);
    }

    public function test_limit_one_per_batch_and_one_per_business_per_30_days(): void
    {
        $business = World::business();
        $gym = $this->makeBatch($business, ['label' => 'Gym']);
        $board = $this->makeBatch($business, ['label' => 'Community board', 'expires_at' => now()->addDays(90)]);

        $claim = $this->claimThroughHttp($gym, '+12125550111');
        $startsAfterFirst = count($this->verify->starts);

        // Same batch again: the limit page, and NO new
        // verification was started for the limited phone.
        $token = $gym->plainToken();
        $this->carryFlowCookie($this->get(route('coupon.landing', ['token' => $token]))->assertOk());
        $limit = $this->post(route('coupon.start', ['token' => $token]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '+12125550111', 'consent' => '1',
        ])->assertOk()->getContent();
        $this->assertStringContainsString('You already have a coupon for', $limit);
        $this->assertSame($startsAfterFirst, count($this->verify->starts));

        // A different batch at the same business inside 30 days:
        // the same limit (rule 2 is what makes rule 1 real).
        $token2 = $board->plainToken();
        $this->carryFlowCookie($this->get(route('coupon.landing', ['token' => $token2]))->assertOk());
        $limit2 = $this->post(route('coupon.start', ['token' => $token2]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '+12125550111', 'consent' => '1',
        ])->assertOk()->getContent();
        $this->assertStringContainsString('You already have a coupon for', $limit2);
        $this->assertSame(1, CouponClaim::count());

        // After the 30-day window rolls, the second batch may
        // claim the same person again.
        $this->travel(31)->days();
        $claim2 = $this->claimThroughHttp($board, '+12125550111');
        $this->assertSame($board->id, $claim2->batch_id);
        $this->assertSame(2, CouponClaim::count());
    }

    public function test_batch_cap_flips_full_and_stops_new_unlocks(): void
    {
        $business = World::business();
        $batch = app(CouponService::class)->createBatch($business, null, 'Gym', '$5 off', 2, 30, 'tab', 'owner');

        $this->claimThroughHttp($batch, '+12125550111');
        $this->claimThroughHttp($batch, '+12125550222');

        $this->assertSame(CouponBatch::STATUS_FULL, $batch->fresh()->status);

        $closed = $this->get(route('coupon.landing', ['token' => $batch->plainToken()]))->assertOk()->getContent();
        $this->assertStringContainsString('This offer reached its limit of 2 coupons.', $closed);
        $this->assertSame(2, CouponClaim::count());
    }

    public function test_kill_and_expiry_stop_new_claims_but_ready_claims_survive(): void
    {
        $business = World::business();
        $batch = $this->makeBatch($business);
        $claim = $this->claimThroughHttp($batch, '+12125550111');

        app(CouponService::class)->killBatch($batch);

        $closed = $this->get(route('coupon.landing', ['token' => $batch->plainToken()]))->assertOk()->getContent();
        $this->assertStringContainsString('has ended.', $closed);

        // The ready claim still opens and still redeems.
        $this->get(route('coupon.claim.page', ['token' => $claim->plainToken()]))->assertOk()
            ->assertSee('Your coupon is ready.');
        $nonce = $this->staffNonce($claim->plainToken());
        $this->post(route('redeem.confirm', ['token' => $claim->plainToken()]), ['nonce' => $nonce])->assertRedirect();
        $this->assertSame(CouponClaim::STATE_REDEEMED, $claim->fresh()->state);

        // An expired batch lands on the same ended form.
        $old = $this->makeBatch($business, ['label' => 'Old', 'expires_at' => now()->subDay()]);
        $closedOld = $this->get(route('coupon.landing', ['token' => $old->plainToken()]))->assertOk()->getContent();
        $this->assertStringContainsString('has ended.', $closedOld);
        $this->assertSame(CouponBatch::STATUS_EXPIRED, $old->fresh()->status);
    }

    public function test_paused_business_blocks_new_unlocks_but_ready_claims_redeem(): void
    {
        $business = World::business();
        $batch = $this->makeBatch($business);
        $claim = $this->claimThroughHttp($batch, '+12125550111');

        // Past due with no datable episode fails closed: the
        // business is dunning-paused on every predicate.
        $business->forceFill(['billing_status' => 'past_due'])->save();
        $this->assertFalse($business->fresh()->couponFlowActive());

        $closed = $this->get(route('coupon.landing', ['token' => $batch->plainToken()]))->assertOk()->getContent();
        $this->assertStringContainsString('is not accepting coupons right now.', $closed);

        $nonce = $this->staffNonce($claim->plainToken());
        $this->post(route('redeem.confirm', ['token' => $claim->plainToken()]), ['nonce' => $nonce])->assertRedirect();
        $this->assertSame(CouponClaim::STATE_REDEEMED, $claim->fresh()->state);
        $this->assertSame(0, BillingEvent::count());
    }

    public function test_closure_window_honours_then_expires_ready_claims(): void
    {
        $business = World::business();
        $batch = $this->makeBatch($business);
        $claim = $this->claimThroughHttp($batch, '+12125550111');
        $claim2 = $this->claimThroughHttp($this->makeBatch($business, ['label' => 'Board']), '+12125550222');

        $business->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

        // Inside the 30-day window the claim still redeems.
        $nonce = $this->staffNonce($claim->plainToken());
        $this->post(route('redeem.confirm', ['token' => $claim->plainToken()]), ['nonce' => $nonce])->assertRedirect();
        $this->assertSame(CouponClaim::STATE_REDEEMED, $claim->fresh()->state);

        // Past the window, the second ready claim expires on read.
        $business->forceFill(['closed_at' => now()->subDays(31)])->save();
        $page = $this->get(route('coupon.claim.page', ['token' => $claim2->plainToken()]))->assertOk()->getContent();
        $this->assertStringContainsString('This coupon expired on', $page);
        $this->assertSame(CouponClaim::STATE_EXPIRED, $claim2->fresh()->state);
    }

    public function test_claim_expires_on_read_after_its_window(): void
    {
        $business = World::business();
        $batch = $this->makeBatch($business);
        $claim = $this->claimThroughHttp($batch, '+12125550111');

        $this->travel(31)->days();

        $page = $this->get(route('coupon.claim.page', ['token' => $claim->plainToken()]))->assertOk()->getContent();
        $this->assertStringContainsString('This coupon expired on', $page);
        $this->assertSame(CouponClaim::STATE_EXPIRED, $claim->fresh()->state);
    }

    // ---------------------------------------------------------------
    // Counter: the shipped act, one delta line, no money
    // ---------------------------------------------------------------

    public function test_staff_redemption_of_a_coupon(): void
    {
        $business = World::business();
        $batch = $this->makeBatch($business);
        $claim = $this->claimThroughHttp($batch, '+12125550111');

        $screen = $this->get(route('redeem.show', ['token' => $claim->plainToken()]))->assertOk()->getContent();
        $this->assertStringContainsString('Coupon: Gym', $screen);
        $this->assertStringContainsString('$5 off your first visit', $screen);
        $this->assertStringContainsString('Mark as Redeemed', $screen);

        $nonce = $this->staffNonce($claim->plainToken());
        $this->post(route('redeem.confirm', ['token' => $claim->plainToken()]), ['nonce' => $nonce])
            ->assertRedirect(route('redeem.show', ['token' => $claim->plainToken()]));

        $fresh = $claim->fresh();
        $this->assertSame(CouponClaim::STATE_REDEEMED, $fresh->state);
        $this->assertNotNull($fresh->redeemed_at);

        // The confirming session's own landing is the success
        // flood (the shipped one-request flash); a later scan
        // shows the already-redeemed truth.
        $landing = $this->get(route('redeem.show', ['token' => $claim->plainToken()]))->assertOk()->getContent();
        $this->assertStringContainsString('Coupon redeemed', $landing);
        $again = $this->get(route('redeem.show', ['token' => $claim->plainToken()]))->assertOk()->getContent();
        $this->assertStringContainsString('Already redeemed', $again);

        // The counter act minted nothing and billed nothing.
        $this->assertSame(0, BillingEvent::count());
        $this->assertSame(0, Reward::count());
        $this->assertSame(0, Referral::count());
    }

    public function test_owner_void_and_its_customer_and_staff_truths(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $batch = $this->makeBatch($business);
        $claim = $this->claimThroughHttp($batch, '+12125550111');

        $this->actingAs($owner)->post(route('owner.qr.coupons.claims.void', ['batch' => $batch, 'claim' => $claim]), [
            'reason' => 'owner_test',
        ])->assertRedirect(route('owner.qr.coupons.claims', ['batch' => $batch]));

        $this->assertSame(CouponClaim::STATE_VOIDED, $claim->fresh()->state);

        $page = $this->get(route('coupon.claim.page', ['token' => $claim->plainToken()]))->assertOk()->getContent();
        $this->assertStringContainsString('This coupon was cancelled by', $page);

        $staff = $this->get(route('redeem.show', ['token' => $claim->plainToken()]))->assertOk()->getContent();
        $this->assertStringContainsString('This coupon was cancelled', $staff);
    }

    // ---------------------------------------------------------------
    // Flag: batches are watched, people are not (pass Section 4.4)
    // ---------------------------------------------------------------

    public function test_flag_marks_the_batch_and_changes_nothing_for_claims(): void
    {
        $business = World::business();
        $batch = $this->makeBatch($business);
        $batch->forceFill(['flagged_at' => now()])->save();

        $owner = World::owner($business);
        $html = $this->actingAs($owner)->get(route('owner.qr.coupons'))->assertOk()->getContent();
        $this->assertStringContainsString('Worth a look:', $html);
        $this->assertStringContainsString('Looks fine', $html);

        $this->actingAs($owner)->post(route('owner.qr.coupons.flag.dismiss', ['batch' => $batch]))->assertRedirect(route('owner.qr.coupons'));
        $this->assertFalse($batch->fresh()->isFlagged());

        // No claim was ever held: the claim table has no held
        // state and the flow mints normally while flagged.
        $batch->forceFill(['flagged_at' => now()])->save();
        $claim = $this->claimThroughHttp($batch, '+12125550111');
        $this->assertSame(CouponClaim::STATE_READY, $claim->state);
    }

    public function test_burst_evaluation_flags_a_batch(): void
    {
        $business = World::business();
        $batch = $this->makeBatch($business, ['claim_cap' => 500]);
        $service = app(CouponService::class);

        for ($i = 0; $i < 10; $i++) {
            $phone = '+1212555'.str_pad((string) (100 + $i), 4, '0', STR_PAD_LEFT);
            $result = $service->mintClaim($batch, [
                'first_name' => 'Pat', 'last_name' => 'Lee', 'phone_e164' => $phone,
            ]);
            $this->assertSame('minted', $result['kind']);
            // Space claims across businesses rule: each mint is
            // a different phone, so only the burst rule can trip.
            CouponClaim::query()->where('id', $result['claim']->id)->update(['created_at' => now()]);
        }

        $this->assertTrue($batch->fresh()->isFlagged());
    }

    // ---------------------------------------------------------------
    // Print + billing surfaces
    // ---------------------------------------------------------------

    public function test_flyer_prints_the_offer_terms_and_fallback_without_owner_guidance(): void
    {
        $business = World::business(['name' => 'Harbor Street Barbers']);
        $owner = World::owner($business);
        $batch = $this->makeBatch($business, ['offer_text' => '$5 off your first visit']);

        $html = $this->actingAs($owner)->get(route('owner.qr.coupons.flyer', ['batch' => $batch]))->assertOk()->getContent();
        $this->assertStringContainsString('$5 off your first visit', $html);
        $this->assertStringContainsString('Scan, verify your phone, show it at the counter.', $html);
        $this->assertStringContainsString('One per person. Good until', $html);
        $this->assertStringContainsString('Harbor Street Barbers, 1 Test Street.', $html);
        // The typed fallback prints as the short link (scheme
        // stripped, the poster family's own pattern), full
        // width, never truncated.
        $this->assertStringContainsString(preg_replace('#^https?://#', '', route('coupon.landing', ['token' => $batch->plainToken()])), $html);
        // Owner guidance and platform framing never print.
        $this->assertStringNotContainsString('These go outside', $html);
        $this->assertStringNotContainsString('batch', $html);
    }

    public function test_flyer_carries_worst_case_content_in_full(): void
    {
        // Print-fit content pin (pass Section 9 item 5): the
        // longest business name, a three-line offer, and the
        // production-shape fallback all print in full; fit is
        // a layout problem, never an excuse to edit the words.
        $business = World::business(['name' => 'The Extraordinarily Long Named Neighborhood Barbershop and Grooming Lounge']);
        $owner = World::owner($business);
        $offer = 'Twenty percent off any full service visit, including a wash, a cut, and a finish, for every new customer who comes in';
        $batch = $this->makeBatch($business, ['offer_text' => $offer]);

        $html = $this->actingAs($owner)->get(route('owner.qr.coupons.flyer', ['batch' => $batch]))->assertOk()->getContent();
        $this->assertStringContainsString('The Extraordinarily Long Named Neighborhood Barbershop and Grooming Lounge', $html);
        $this->assertStringContainsString($offer, $html);
        $this->assertStringContainsString(preg_replace('#^https?://#', '', route('coupon.landing', ['token' => $batch->plainToken()])), $html);
        $this->assertStringNotContainsString('...', $html);
    }

    public function test_billing_page_carries_the_never_charged_line(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $html = $this->actingAs($owner)->get(route('settings.billing'))->assertOk()->getContent();
        $this->assertStringContainsString('Coupon claims are never charged. You only pay when a referred customer walks in and redeems.', $html);
    }

    // ---------------------------------------------------------------
    // Negative census on the customer surfaces (pass Section 7.5)
    // ---------------------------------------------------------------

    public function test_customer_coupon_surfaces_carry_no_referral_vocabulary(): void
    {
        $business = World::business();
        $batch = $this->makeBatch($business);

        $surfaces = [];
        $surfaces[] = $this->get(route('coupon.landing', ['token' => $batch->plainToken()]))->assertOk()->getContent();

        $claim = $this->claimThroughHttp($batch, '+12125550111');
        $surfaces[] = $this->get(route('coupon.claim.page', ['token' => $claim->plainToken()]))->assertOk()->getContent();

        $killed = $this->makeBatch($business, ['label' => 'Old']);
        app(CouponService::class)->killBatch($killed);
        $surfaces[] = $this->get(route('coupon.landing', ['token' => $killed->plainToken()]))->assertOk()->getContent();

        foreach ($surfaces as $html) {
            foreach (['friend', 'Friend', 'referral', 'Referral', 'referred by', 'vesting', 'visit that counts', 'share your link', 'Share your link'] as $banned) {
                $this->assertStringNotContainsString($banned, $html, "banned vocabulary on a coupon surface: {$banned}");
            }
        }
    }

    // ---------------------------------------------------------------
    // Kill metric computability (pass Section 8.2)
    // ---------------------------------------------------------------

    public function test_kill_metric_inputs_are_computable_from_stored_rows(): void
    {
        $business = World::business();
        $batch = $this->makeBatch($business, ['status' => CouponBatch::STATUS_EXPIRED, 'expires_at' => now()->subDays(40)]);

        // Five claims, two redeemed, all windows closed.
        for ($i = 0; $i < 5; $i++) {
            $phone = '+1212555'.str_pad((string) (200 + $i), 4, '0', STR_PAD_LEFT);
            $customer = \App\Models\Customer::create([
                'phone_e164' => $phone, 'first_name' => 'Pat', 'last_name' => 'Lee', 'first_seen_at' => now()->subDays(50),
            ]);
            $token = OpaqueToken::generate();
            CouponClaim::create([
                'business_id' => $business->id,
                'batch_id' => $batch->id,
                'customer_id' => $customer->id,
                'source' => 'coupon',
                'state' => $i < 2 ? CouponClaim::STATE_REDEEMED : CouponClaim::STATE_EXPIRED,
                'offer_text' => '$5 off',
                'redeem_token_hash' => OpaqueToken::hash($token),
                'redeem_token_encrypted' => $token,
                'expires_at' => now()->subDays(10),
                'redeemed_at' => $i < 2 ? now()->subDays(20) : null,
            ]);
        }

        $readout = app(CouponService::class)->channelReadout();
        $this->assertSame(1, $readout['qualifying_batches']);
        $this->assertEqualsWithDelta(0.4, $readout['median_conversion'], 0.001);
        // Scan-to-claim is computable per batch from the same rows.
        $funnel = app(CouponService::class)->batchFunnel($batch);
        $this->assertSame(5, $funnel['claimed']);
        $this->assertSame(2, $funnel['redeemed']);
        $this->assertEqualsWithDelta(0.4, $funnel['claim_to_redemption'], 0.001);
    }
}
