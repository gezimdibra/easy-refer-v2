<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\Reward;
use App\Services\Referral\EnrollmentService;
use App\Services\Referral\RedemptionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * BREAKER-CRITICAL hotfix pins (breaker-report-2026-10-29, D-1 + D-2).
 *
 * D-1 (CRITICAL): the documented dedupe per (business, friend)
 * (COMPANY-KNOWLEDGE §3) fired only while a referral was pending.
 * The same friend phone running the ordinary claim -> verify ->
 * staff-confirm flow a SECOND time at the same business minted a
 * second referral, a second referrer reward, and a second $2.00
 * billing event. The dedupe truth: a visit counts ONCE per
 * (business, friend). Lifecycle semantics pinned here, read from
 * the shipped code + COMPANY-KNOWLEDGE, not invented:
 *  - pending + live      -> resolves to the existing referral
 *                           (the shipped 'existing' shape);
 *  - redeemed            -> resolves to the existing referral; the
 *                           friend lands on their existing visit /
 *                           reward page. Never a second mint;
 *  - voided (denied)     -> refused with the existing referral; an
 *                           owner's deny is terminal for that pair
 *                           (a fresh claim would defeat the deny);
 *  - expired             -> the slot is FREE: the shipped dedupe
 *                           deliberately flips an expired pending
 *                           referral and creates a new one, because
 *                           no visit ever counted. A new claim is
 *                           legal and redeems/mints exactly once.
 *
 * D-2 (HIGH): review deny acted on never-held rows and reversed a
 * completed release. Deny is legal ONLY from a held, unresolved
 * review; release is a TERMINAL resolution (release-then-deny is
 * refused); deny is terminal too (deny-then-release cannot
 * resurrect); replays are refused without state change.
 */
class BreakerCriticalFixTest extends TestCase
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

    // ---------------------------------------------------------------
    // Flow helpers (the Breaker's ordinary-input path)
    // ---------------------------------------------------------------

    private function carryFlowCookie(\Illuminate\Testing\TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'er2_flow') {
                $this->withUnencryptedCookie('er2_flow', $cookie->getValue());
            }
        }
    }

    /** @return array{0: Membership, 1: string} referrer membership + share token, link aged past the 25-minute gap */
    private function referrerAged(Business $business, string $phone): array
    {
        $customer = World::customer($phone, 'Sam', 'Reed');
        [$membership, $token] = World::membership($business, $customer);
        $membership->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

        return [$membership->refresh(), $token];
    }

    /** One full HTTP claim pass for $phone through $shareToken; returns the response of the verify step (or of the claim step when it does not reach verify). */
    private function httpClaimPass(string $shareToken, string $phone = '2125550193'): \Illuminate\Testing\TestResponse
    {
        $this->carryFlowCookie($this->get(route('refer.friend', ['token' => $shareToken]))->assertOk());
        $claim = $this->post(route('refer.friend.claim', ['token' => $shareToken]), [
            'first_name' => 'Jamie', 'last_name' => 'Friend', 'phone' => $phone, 'consent' => '1',
        ]);

        if (! $claim->headers->get('Location') || ! str_contains((string) $claim->headers->get('Location'), 'verify')) {
            return $claim;
        }

        $this->carryFlowCookie($claim->assertRedirect(route('refer.verify')));

        return $this->post(route('refer.verify.check'), ['code' => '123456']);
    }

    /** Staff-confirm whatever pending referral $token names, if it is still pending (the Breaker's second press). */
    private function staffConfirmIfPending(string $plainToken): void
    {
        $referral = Referral::query()->where('redeem_token_hash', \App\Support\OpaqueToken::hash($plainToken))->first();

        if ($referral === null || $referral->state !== Referral::STATE_PENDING) {
            return;
        }

        $show = $this->get(route('redeem.show', ['token' => $plainToken]))->assertOk();
        preg_match('/name="nonce" value="([^"]+)"/', $show->getContent(), $m);
        $this->assertNotEmpty($m, 'a pending referral must offer a staff confirm nonce');
        $this->post(route('redeem.confirm', ['token' => $plainToken]), ['nonce' => $m[1]])
            ->assertRedirect(route('redeem.show', ['token' => $plainToken]));
    }

    private function claimService(Membership $referrer, string $phone = '+12125550193'): array
    {
        return app(EnrollmentService::class)->completeClaim($referrer, [
            'first_name' => 'Jamie', 'last_name' => 'Friend', 'phone_e164' => $phone, 'consent' => true,
        ], '127.0.0.1', null);
    }

    private function moneyCounts(Business $business): array
    {
        return [
            'referrals' => Referral::query()->where('business_id', $business->id)->count(),
            'rewards' => Reward::query()->where('business_id', $business->id)->count(),
            'billing_events' => BillingEvent::query()->where('business_id', $business->id)->count(),
            'billing_cents' => (int) BillingEvent::query()->where('business_id', $business->id)->sum('amount_cents'),
        ];
    }

    // ---------------------------------------------------------------
    // D-1 — the Breaker's exact scenario (HTTP, ordinary inputs)
    // ---------------------------------------------------------------

    public function test_breaker_scenario_second_full_http_pass_mints_and_bills_nothing_twice(): void
    {
        $business = World::business();
        [, $shareToken] = $this->referrerAged($business, '+12125550100');

        // Pass 1: claim -> verify -> staff confirm.
        $this->httpClaimPass($shareToken)->assertRedirect();
        $first = Referral::firstOrFail();
        $this->staffConfirmIfPending($first->redeem_token_encrypted);
        $this->assertSame(Referral::STATE_REDEEMED, $first->fresh()->state);
        $this->assertSame(['referrals' => 1, 'rewards' => 1, 'billing_events' => 1, 'billing_cents' => 200], $this->moneyCounts($business));

        // Pass 2: the SAME phone runs the identical flow again
        // (the Breaker's M4). Follow whatever path the flow
        // offers, including a staff confirm if a new pending
        // referral somehow appears.
        $verify2 = $this->httpClaimPass($shareToken);
        $second = Referral::query()->where('id', '!=', $first->id)->first();
        if ($second !== null) {
            $this->staffConfirmIfPending($second->redeem_token_encrypted);
        }

        $this->assertSame(
            ['referrals' => 1, 'rewards' => 1, 'billing_events' => 1, 'billing_cents' => 200],
            $this->moneyCounts($business),
            'the second full pass must not mint a second referral, reward, or $2.00 billing event'
        );
    }

    public function test_second_http_pass_lands_the_friend_on_their_existing_visit_page(): void
    {
        $business = World::business();
        [, $shareToken] = $this->referrerAged($business, '+12125550101');

        $this->httpClaimPass($shareToken)->assertRedirect();
        $first = Referral::firstOrFail();
        $this->staffConfirmIfPending($first->redeem_token_encrypted);
        $this->assertSame(Referral::STATE_REDEEMED, $first->fresh()->state);

        // Second pass: after verification the friend is routed to
        // the FIRST referral's own page (their existing reward /
        // visit receipt), not a new referral and not a wall.
        $verify2 = $this->httpClaimPass($shareToken);
        $verify2->assertRedirect(route('refer.redeem.page', ['token' => $first->redeem_token_encrypted]));
        $this->assertSame(1, Referral::count());
    }

    // ---------------------------------------------------------------
    // D-1 — verify-completion gate (EnrollmentService::completeClaim)
    // ---------------------------------------------------------------

    public function test_verify_completion_after_redemption_resolves_to_the_existing_referral(): void
    {
        $business = World::business();
        [$referrer] = $this->referrerAged($business, '+12125550102');

        $first = $this->claimService($referrer);
        $this->assertSame('created', $first['kind']);
        $redeemed = app(RedemptionService::class)->redeemReferral($first['referral'], null, '127.0.0.1');
        $this->assertSame('redeemed', $redeemed['outcome']);
        $this->assertSame(['referrals' => 1, 'rewards' => 1, 'billing_events' => 1, 'billing_cents' => 200], $this->moneyCounts($business));

        $second = $this->claimService($referrer->fresh());

        $this->assertSame('already_redeemed', $second['kind']);
        $this->assertSame($first['referral']->id, $second['referral']->id);
        $this->assertSame(['referrals' => 1, 'rewards' => 1, 'billing_events' => 1, 'billing_cents' => 200], $this->moneyCounts($business));
        $this->assertSame(1, (int) $referrer->fresh()->referral_count, 'a deduped claim does not re-count toward the referrer');
    }

    public function test_second_claim_through_a_different_referrers_link_resolves_to_the_existing_referral(): void
    {
        // The dedupe key is (business, friend), NOT the link or the
        // referrer: a different code / placement / referrer of the
        // same business changes nothing.
        $business = World::business();
        [$referrerA] = $this->referrerAged($business, '+12125550103');
        [$referrerB] = $this->referrerAged($business, '+12125550104');

        $first = $this->claimService($referrerA);
        app(RedemptionService::class)->redeemReferral($first['referral'], null, '127.0.0.1');

        $second = $this->claimService($referrerB->fresh());

        $this->assertSame('already_redeemed', $second['kind']);
        $this->assertSame($first['referral']->id, $second['referral']->id);
        $this->assertSame(['referrals' => 1, 'rewards' => 1, 'billing_events' => 1, 'billing_cents' => 200], $this->moneyCounts($business));
    }

    public function test_the_same_friend_at_a_different_business_is_a_fresh_claim(): void
    {
        $businessA = World::business();
        [$referrerA] = $this->referrerAged($businessA, '+12125550105');
        $first = $this->claimService($referrerA);
        app(RedemptionService::class)->redeemReferral($first['referral'], null, '127.0.0.1');

        $businessB = World::business();
        [$referrerB] = $this->referrerAged($businessB, '+12125550106');
        $second = $this->claimService($referrerB);

        $this->assertSame('created', $second['kind']);
        $this->assertSame(['referrals' => 1, 'rewards' => 0, 'billing_events' => 0, 'billing_cents' => 0], $this->moneyCounts($businessB));
    }

    public function test_claim_after_the_first_referral_expired_is_a_new_claim_that_counts_once(): void
    {
        // Lifecycle truth (shipped dedupe comment): an expired
        // pending referral frees the slot — no visit ever counted,
        // so a later claim is legal and counts exactly once.
        $business = World::business();
        [$referrer] = $this->referrerAged($business, '+12125550107');

        $first = $this->claimService($referrer);
        $this->assertSame('created', $first['kind']);
        $first['referral']->forceFill(['expires_at' => now()->subMinute()])->save();

        $second = $this->claimService($referrer->fresh());

        $this->assertSame('created', $second['kind']);
        $this->assertNotSame($first['referral']->id, $second['referral']->id);
        $this->assertSame(Referral::STATE_EXPIRED, $first['referral']->fresh()->state);
        $this->assertSame(2, Referral::count());

        app(RedemptionService::class)->redeemReferral($second['referral'], null, '127.0.0.1');
        $this->assertSame(['referrals' => 2, 'rewards' => 1, 'billing_events' => 1, 'billing_cents' => 200], $this->moneyCounts($business));
    }

    public function test_claim_after_the_first_referral_was_denied_is_refused_and_mints_nothing(): void
    {
        $business = World::business();
        [$referrer] = $this->referrerAged($business, '+12125550108');

        $first = $this->claimService($referrer);
        $this->assertSame('created', $first['kind']);
        // A held referral denied by the owner (the only legal deny
        // after the D-2 fix) is terminal for this (business,
        // friend) pair: a fresh claim would defeat the deny.
        $first['referral']->forceFill(['hold' => true, 'held_at' => now(), 'hold_rules' => ['device_signal_reuse']])->save();
        $this->assertTrue(app(RedemptionService::class)->denyReferral($first['referral']->fresh()));
        $this->assertSame(Referral::STATE_VOIDED, $first['referral']->fresh()->state);

        $second = $this->claimService($referrer->fresh());

        $this->assertSame('denied', $second['kind']);
        $this->assertSame($first['referral']->id, $second['referral']->id);
        $this->assertSame(['referrals' => 1, 'rewards' => 0, 'billing_events' => 0, 'billing_cents' => 0], $this->moneyCounts($business));
    }

    public function test_http_claim_after_deny_never_starts_a_new_verification(): void
    {
        $business = World::business();
        [$referrer, $shareToken] = $this->referrerAged($business, '+12125550109');

        $first = $this->claimService($referrer);
        $first['referral']->forceFill(['hold' => true, 'held_at' => now(), 'hold_rules' => ['device_signal_reuse']])->save();
        $this->assertTrue(app(RedemptionService::class)->denyReferral($first['referral']->fresh()));

        $startsBefore = count($this->verify->starts);
        $response = $this->httpClaimPass($shareToken);

        // The claim step itself resolves to the existing (voided)
        // referral's page: no OTP is burned on a claim that can
        // never complete, and no new referral appears.
        $response->assertRedirect(route('refer.redeem.page', ['token' => $first['referral']->redeem_token_encrypted]));
        $this->assertSame($startsBefore, count($this->verify->starts));
        $this->assertSame(1, Referral::count());
    }

    // ---------------------------------------------------------------
    // D-1 — redemption gate + data layer
    // ---------------------------------------------------------------

    public function test_redemption_gate_replay_mints_and_bills_nothing_twice(): void
    {
        $business = World::business();
        [$referrer] = $this->referrerAged($business, '+12125550110');

        $first = $this->claimService($referrer);
        $service = app(RedemptionService::class);
        $this->assertSame('redeemed', $service->redeemReferral($first['referral'], null, '127.0.0.1')['outcome']);
        $replay = $service->redeemReferral($first['referral']->fresh(), null, '127.0.0.1');

        $this->assertSame('already', $replay['outcome']);
        $this->assertSame(['referrals' => 1, 'rewards' => 1, 'billing_events' => 1, 'billing_cents' => 200], $this->moneyCounts($business));
    }

    public function test_data_layer_rejects_a_second_counted_referral_for_the_pair(): void
    {
        $business = World::business();
        [$referrer] = $this->referrerAged($business, '+12125550111');
        $friend = World::customer('+12125550193', 'Jamie', 'Friend');

        $first = $this->claimService($referrer);
        app(RedemptionService::class)->redeemReferral($first['referral'], null, '127.0.0.1');

        // Even bypassing every service, the database refuses a
        // second pending referral for a pair that already counted.
        $this->expectException(QueryException::class);
        World::referral($referrer, $friend);
    }

    public function test_data_layer_admits_expired_history_but_not_a_second_voided_pair(): void
    {
        $business = World::business();
        [$referrer] = $this->referrerAged($business, '+12125550112');
        $friend = World::customer('+12125550194', 'Jamie', 'Friend');

        // Expired rows carry no pair key: history may accumulate.
        World::referral($referrer, $friend, ['state' => Referral::STATE_EXPIRED]);
        World::referral($referrer, $friend, ['state' => Referral::STATE_EXPIRED]);
        $this->assertSame(2, Referral::count());

        // A voided (denied) row occupies the pair.
        World::referral($referrer, $friend, ['state' => Referral::STATE_VOIDED]);
        $this->expectException(QueryException::class);
        World::referral($referrer, $friend);
    }

    // ---------------------------------------------------------------
    // D-2 — deny guard (service level)
    // ---------------------------------------------------------------

    /** A converted held visit (Option B): friend completed, referrer reward + billing held. @return array{0: array, 1: BillingEvent} */
    private function heldVisit(Business $business): array
    {
        $business->forceFill(['daily_referral_cap' => 1])->save();
        [$referrer] = $this->referrerAged($business, '+12125550120');

        $clean = $this->claimService($referrer, '+12125550121');
        app(RedemptionService::class)->redeemReferral($clean['referral'], null, '127.0.0.1');

        $flagged = $this->claimService($referrer->fresh(), '+12125550122');
        $flagged['referral']->forceFill(['risk_score' => 50, 'risk_rules' => ['device_signal_reuse']])->save();
        $result = app(RedemptionService::class)->redeemReferral($flagged['referral']->fresh(), null, '127.0.0.1');
        $this->assertSame('redeemed', $result['outcome']);
        $this->assertTrue($result['reward']->hold);

        return [$result, BillingEvent::query()->where('referral_id', $flagged['referral']->id)->firstOrFail()];
    }

    public function test_deny_referral_refused_when_the_referral_was_never_held(): void
    {
        // Breaker D-2 case 1: referral 10, pending_redemption,
        // never held -> deny voided it. Now refused.
        $business = World::business();
        [$membership] = World::membership($business, World::customer('+12125550123', 'Reg', 'Ular'));
        [$referral] = World::referral($membership, World::customer('+12125550124', 'Fri', 'End'));

        $this->assertFalse((bool) $referral->fresh()->hold);
        $this->assertFalse(app(RedemptionService::class)->denyReferral($referral));
        $this->assertSame(Referral::STATE_PENDING, $referral->fresh()->state);
        $this->assertSame(0, \App\Models\ReferralEvent::query()->where('event_type', 'review_deny')->count());
    }

    public function test_deny_reward_refused_when_the_reward_is_available_and_never_held(): void
    {
        // Breaker D-2 case 2: reward 6, available, never held ->
        // deny removed a ready-to-claim reward. Now refused.
        $business = World::business();
        [$membership] = World::membership($business, World::customer('+12125550125', 'Reg', 'Ular'));
        [$referral] = World::referral($membership, World::customer('+12125550126', 'Fri', 'End'));
        [$reward] = World::reward($membership, $referral, ['state' => Reward::STATE_AVAILABLE, 'hold' => false]);

        $this->assertFalse(app(RedemptionService::class)->denyReward($reward));
        $this->assertSame(Reward::STATE_AVAILABLE, $reward->fresh()->state);
    }

    public function test_deny_reward_refused_when_the_reward_is_vesting_and_never_held(): void
    {
        $business = World::business();
        [$membership] = World::membership($business, World::customer('+12125550127', 'Reg', 'Ular'));
        [$referral] = World::referral($membership, World::customer('+12125550128', 'Fri', 'End'));
        [$reward] = World::reward($membership, $referral, ['state' => Reward::STATE_PENDING_VESTING, 'hold' => false]);

        $this->assertFalse(app(RedemptionService::class)->denyReward($reward));
        $this->assertSame(Reward::STATE_PENDING_VESTING, $reward->fresh()->state);
    }

    public function test_deny_from_held_still_works_for_a_referral_and_a_reward(): void
    {
        $business = World::business();
        [$membership] = World::membership($business, World::customer('+12125550129', 'Reg', 'Ular'));
        [$heldReferral] = World::referral($membership, World::customer('+12125550130', 'Fri', 'End'), [
            'hold' => true, 'held_at' => now(), 'hold_rules' => ['device_signal_reuse'],
        ]);
        $this->assertTrue(app(RedemptionService::class)->denyReferral($heldReferral));
        $this->assertSame(Referral::STATE_VOIDED, $heldReferral->fresh()->state);
        $this->assertNotNull($heldReferral->fresh()->hold_resolved_at, 'a deny stamps the resolution');

        [$result, $event] = $this->heldVisit(World::business());
        $this->assertTrue(app(RedemptionService::class)->denyReward($result['reward']));
        $this->assertSame(Reward::STATE_DENIED, $result['reward']->fresh()->state);
        $this->assertSame('fraud_denied', $event->fresh()->coverage);
    }

    public function test_release_is_terminal_release_then_deny_is_refused_and_billing_stays_billable(): void
    {
        // Breaker D-2 case 3: release completed, then a replayed
        // deny flipped reward -> denied and billing -> fraud_denied.
        [$result, $event] = $this->heldVisit(World::business());
        $service = app(RedemptionService::class);

        $service->releaseReward($result['reward']);
        $released = $result['reward']->fresh();
        $this->assertFalse($released->hold);
        $this->assertNotNull($released->hold_resolved_at, 'a release stamps the terminal resolution');
        $this->assertSame('billable', $event->fresh()->coverage);

        $this->assertFalse($service->denyReward($released));
        $this->assertSame($released->state, $result['reward']->fresh()->state);
        $this->assertNotSame(Reward::STATE_DENIED, $result['reward']->fresh()->state);
        $this->assertSame('billable', $event->fresh()->coverage, 'a post-release deny must not touch billing');
    }

    public function test_release_then_deny_is_refused_for_a_held_referral_too(): void
    {
        $business = World::business();
        [$membership] = World::membership($business, World::customer('+12125550131', 'Reg', 'Ular'));
        [$referral] = World::referral($membership, World::customer('+12125550132', 'Fri', 'End'), [
            'hold' => true, 'held_at' => now(), 'hold_rules' => ['device_signal_reuse'],
        ]);
        $service = app(RedemptionService::class);

        $service->releaseReferral($referral);
        $this->assertNotNull($referral->fresh()->hold_resolved_at);

        $this->assertFalse($service->denyReferral($referral->fresh()));
        $this->assertSame(Referral::STATE_PENDING, $referral->fresh()->state);
    }

    public function test_deny_twice_the_second_deny_is_refused_and_nothing_moves(): void
    {
        [$result, $event] = $this->heldVisit(World::business());
        $service = app(RedemptionService::class);

        $this->assertTrue($service->denyReward($result['reward']));
        $this->assertFalse($service->denyReward($result['reward']->fresh()));
        $this->assertSame(Reward::STATE_DENIED, $result['reward']->fresh()->state);
        $this->assertSame('fraud_denied', $event->fresh()->coverage);
        $this->assertSame(1, \App\Models\ReferralEvent::query()->where('event_type', 'review_deny')->count());
    }

    public function test_release_after_deny_cannot_resurrect_the_reward_or_its_billing(): void
    {
        [$result, $event] = $this->heldVisit(World::business());
        $service = app(RedemptionService::class);

        $this->assertTrue($service->denyReward($result['reward']));
        $service->releaseReward($result['reward']->fresh());

        $this->assertSame(Reward::STATE_DENIED, $result['reward']->fresh()->state);
        $this->assertSame('fraud_denied', $event->fresh()->coverage);
    }

    public function test_release_replay_is_an_idempotent_no_op(): void
    {
        [$result, $event] = $this->heldVisit(World::business());
        $service = app(RedemptionService::class);

        $service->releaseReward($result['reward']);
        $resolvedAt = $result['reward']->fresh()->hold_resolved_at;
        $events = \App\Models\ReferralEvent::query()->where('event_type', 'review_release')->count();

        $service->releaseReward($result['reward']->fresh());

        $this->assertEquals($resolvedAt, $result['reward']->fresh()->hold_resolved_at);
        $this->assertSame($events, \App\Models\ReferralEvent::query()->where('event_type', 'review_release')->count(), 'a replayed release writes no second resolution');
        $this->assertSame('billable', $event->fresh()->coverage);
    }

    // ---------------------------------------------------------------
    // D-2 — HTTP surface: refusal names the state
    // ---------------------------------------------------------------

    public function test_http_deny_of_a_never_held_referral_is_refused_with_its_state_named(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550133', 'Reg', 'Ular'));
        [$referral] = World::referral($membership, World::customer('+12125550134', 'Fri', 'End'));

        $response = $this->actingAs($owner)->post(route('reviews.referrals.deny', $referral));
        $response->assertRedirect();
        $response->assertSessionHas('status', fn ($status) => str_contains((string) $status, 'waiting for its visit'));
        $this->assertSame(Referral::STATE_PENDING, $referral->fresh()->state);
    }

    public function test_http_deny_of_a_never_held_available_reward_is_refused_with_its_state_named(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550135', 'Reg', 'Ular'));
        [$referral] = World::referral($membership, World::customer('+12125550136', 'Fri', 'End'));
        [$reward] = World::reward($membership, $referral, ['state' => Reward::STATE_AVAILABLE, 'hold' => false]);

        $response = $this->actingAs($owner)->post(route('reviews.rewards.deny', $reward));
        $response->assertRedirect();
        $response->assertSessionHas('status', fn ($status) => str_contains((string) $status, 'available'));
        $this->assertSame(Reward::STATE_AVAILABLE, $reward->fresh()->state);
    }

    public function test_http_deny_and_release_of_held_items_still_work(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550137', 'Reg', 'Ular'));
        [$heldDeny] = World::referral($membership, World::customer('+12125550138', 'Fri', 'End'), [
            'hold' => true, 'held_at' => now(), 'hold_rules' => ['device_signal_reuse'],
        ]);
        [$heldRelease] = World::referral($membership, World::customer('+12125550139', 'Fri', 'End'), [
            'hold' => true, 'held_at' => now(), 'hold_rules' => ['device_signal_reuse'],
        ]);

        $this->actingAs($owner)->post(route('reviews.referrals.deny', $heldDeny))->assertRedirect();
        $this->assertSame(Referral::STATE_VOIDED, $heldDeny->fresh()->state);

        $this->actingAs($owner)->post(route('reviews.referrals.release', $heldRelease))->assertRedirect();
        $this->assertFalse($heldRelease->fresh()->hold);
        $this->assertNotNull($heldRelease->fresh()->hold_resolved_at);

        // And the released one can no longer be denied over HTTP.
        $response = $this->actingAs($owner)->post(route('reviews.referrals.deny', $heldRelease->fresh()));
        $response->assertSessionHas('status', fn ($status) => str_contains((string) $status, 'released'));
        $this->assertSame(Referral::STATE_PENDING, $heldRelease->fresh()->state);
    }
}
