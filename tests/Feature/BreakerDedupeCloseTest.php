<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\Reward;
use App\Services\Billing\RewardClawback;
use App\Services\Referral\EnrollmentService;
use App\Services\Referral\RedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * BREAKER DEDUPE CLOSE pins — two-key Findings 3, 4, and 5 on
 * hotfix/breaker-critical (Findings 1 + 2 live in
 * BreakerDedupeMigrationTest).
 *
 * Finding 3 (HIGH): RewardClawback's cancel branch denied a
 * reward WITHOUT stamping hold_resolved_at, so releaseReward()
 * could flip a clawback-denied visit's held BillingEvent back
 * to billable. The clawback now stamps, AND both release
 * methods no-op on a terminal denied/voided state regardless
 * of any stamp.
 *
 * Finding 4 (MEDIUM): the redemption pair guard was unpinned
 * and, on a migrated DB, unreachable by design (the unique
 * index forbids its input). It is an honest redundant belt:
 * pinned here by recreating the pre-dedupe duplicate with the
 * index dropped, exactly the reviewer's prescribed shape.
 *
 * Finding 5 (MEDIUM): the "denied" verify-completion landing
 * was unproven. Pinned end to end over HTTP with the
 * claim-step shortcut NOT firing (a live pending row makes the
 * gate skip; the pair is denied between claim and verify), and
 * at the service layer for the gate-skip shape the reviewer
 * named (time-expired pending row + voided row).
 */
class BreakerDedupeCloseTest extends TestCase
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
    // Helpers (mirrors of BreakerCriticalFixTest's flow shapes)
    // ---------------------------------------------------------------

    private function carryFlowCookie(\Illuminate\Testing\TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'er2_flow') {
                $this->withUnencryptedCookie('er2_flow', $cookie->getValue());
            }
        }
    }

    /** @return array{0: Membership, 1: string} */
    private function referrerAged(Business $business, string $phone): array
    {
        $customer = World::customer($phone, 'Sam', 'Reed');
        [$membership, $token] = World::membership($business, $customer);
        $membership->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

        return [$membership->refresh(), $token];
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

    /** A converted held visit (Option B), as in BreakerCriticalFixTest. @return array{0: array, 1: BillingEvent} */
    private function heldVisit(Business $business): array
    {
        $business->forceFill(['daily_referral_cap' => 1])->save();
        [$referrer] = $this->referrerAged($business, '+12125550420');

        $clean = $this->claimService($referrer, '+12125550421');
        app(RedemptionService::class)->redeemReferral($clean['referral'], null, '127.0.0.1');

        $flagged = $this->claimService($referrer->fresh(), '+12125550422');
        $flagged['referral']->forceFill(['risk_score' => 50, 'risk_rules' => ['device_signal_reuse']])->save();
        $result = app(RedemptionService::class)->redeemReferral($flagged['referral']->fresh(), null, '127.0.0.1');
        $this->assertSame('redeemed', $result['outcome']);
        $this->assertTrue($result['reward']->hold);

        return [$result, BillingEvent::query()->where('referral_id', $flagged['referral']->id)->firstOrFail()];
    }

    // ---------------------------------------------------------------
    // Finding 3 — the clawback deny writer + terminal hardening
    // ---------------------------------------------------------------

    public function test_clawback_cancel_stamps_the_resolution_and_release_after_it_cannot_flip_billing(): void
    {
        // The reviewer's exact path: clawback-cancel a held
        // visit's reward, then releaseReward().
        [$result, $event] = $this->heldVisit(World::business());
        $reward = $result['reward'];
        $this->assertSame('held', $event->fresh()->coverage);
        $this->assertSame(Reward::STATE_PENDING_VESTING, $reward->fresh()->state);

        $outcome = app(RewardClawback::class)->clawbackEvent($event->fresh());

        $this->assertSame('canceled', $outcome);
        $denied = $reward->fresh();
        $this->assertSame(Reward::STATE_DENIED, $denied->state);
        $this->assertFalse($denied->hold);
        $this->assertNotNull($denied->hold_resolved_at, 'the clawback cancel stamps the resolution like every deny writer');

        app(RedemptionService::class)->releaseReward($denied);

        $this->assertSame(Reward::STATE_DENIED, $reward->fresh()->state, 'release after a clawback deny cannot resurrect the reward');
        $this->assertSame('held', $event->fresh()->coverage, 'release after a clawback deny cannot flip the held billing event to billable');
        $this->assertSame(0, ReferralEvent::query()->where('event_type', 'review_release')->where('reward_id', $reward->id)->count());
    }

    public function test_release_reward_on_an_unstamped_denied_reward_is_a_no_op(): void
    {
        // Terminal-state hardening, stamp-independent: a denied
        // reward whose hold_resolved_at is NULL (the legacy /
        // forgetful-writer shape) still cannot be released.
        $business = World::business();
        [$membership] = World::membership($business, World::customer('+12125550430', 'Reg', 'Ular'));
        [$referral] = World::referral($membership, World::customer('+12125550431', 'Fri', 'End'), ['state' => Referral::STATE_REDEEMED]);
        [$reward] = World::reward($membership, $referral, ['state' => Reward::STATE_DENIED, 'hold' => false, 'hold_resolved_at' => null]);
        $event = BillingEvent::create(['business_id' => $business->id, 'referral_id' => $referral->id, 'amount_cents' => 200, 'coverage' => 'held']);

        app(RedemptionService::class)->releaseReward($reward);

        $this->assertSame(Reward::STATE_DENIED, $reward->fresh()->state);
        $this->assertSame('held', $event->fresh()->coverage);
        $this->assertSame(0, ReferralEvent::query()->where('event_type', 'review_release')->count());
    }

    public function test_release_referral_on_a_voided_referral_is_a_no_op_even_without_a_stamp(): void
    {
        $business = World::business();
        [$membership] = World::membership($business, World::customer('+12125550432', 'Reg', 'Ular'));
        [$referral] = World::referral($membership, World::customer('+12125550433', 'Fri', 'End'), [
            'state' => Referral::STATE_VOIDED, 'hold' => false, 'hold_resolved_at' => null,
        ]);

        app(RedemptionService::class)->releaseReferral($referral);

        $this->assertSame(Referral::STATE_VOIDED, $referral->fresh()->state);
        $this->assertSame(0, ReferralEvent::query()->where('event_type', 'review_release')->count());
    }

    // ---------------------------------------------------------------
    // Finding 4 — the redemption pair guard, fired on a true
    // pre-dedupe duplicate (index dropped, per the reviewer)
    // ---------------------------------------------------------------

    public function test_redemption_pair_guard_fires_on_a_pre_dedupe_duplicate_with_the_index_dropped(): void
    {
        $business = World::business();
        [$referrer] = $this->referrerAged($business, '+12125550440');

        $first = $this->claimService($referrer, '+12125550441');
        $service = app(RedemptionService::class);
        $redeemed = $service->redeemReferral($first['referral'], null, '127.0.0.1');
        $this->assertSame('redeemed', $redeemed['outcome']);
        $this->assertSame(['referrals' => 1, 'rewards' => 1, 'billing_events' => 1, 'billing_cents' => 200], $this->moneyCounts($business));

        // The pre-dedupe shape: a second PENDING referral for a
        // pair that already redeemed. Post-migration the unique
        // index forbids this row, so the guard is a redundant
        // belt — recreate its input with the index dropped, as
        // the reviewer prescribed, and prove it fires.
        Schema::table('referrals', function ($table) {
            $table->dropUnique(['counted_pair_key']);
        });
        $friend = World::customer('+12125550441', 'Jamie', 'Friend');
        [$duplicate, $duplicateToken] = World::referral($referrer, $friend);
        $this->assertSame(Referral::STATE_PENDING, $duplicate->fresh()->state);

        $guarded = $service->redeemReferral($duplicate->fresh(), null, '127.0.0.1');

        $this->assertSame('already', $guarded['outcome']);
        $this->assertSame($duplicate->id, $guarded['referral']->id, 'the presented duplicate is the row that stays un-counted');
        $this->assertSame($redeemed['reward']->id, $guarded['reward']->id, 'the reward named is the COUNTED referral\'s reward');
        $this->assertSame(Referral::STATE_PENDING, $duplicate->fresh()->state, 'the duplicate stays pending and expires on its own clock');
        $this->assertSame(['referrals' => 2, 'rewards' => 1, 'billing_events' => 1, 'billing_cents' => 200], $this->moneyCounts($business));

        // Staff surface end to end: the duplicate's own staff
        // confirm press completes nothing and flashes no success.
        $show = $this->get(route('redeem.show', ['token' => $duplicateToken]))->assertOk();
        preg_match('/name="nonce" value="([^"]+)"/', $show->getContent(), $m);
        $this->assertNotEmpty($m);
        $this->post(route('redeem.confirm', ['token' => $duplicateToken]), ['nonce' => $m[1]])
            ->assertRedirect(route('redeem.show', ['token' => $duplicateToken]))
            ->assertSessionMissing('staff_success');
        $this->assertSame(['referrals' => 2, 'rewards' => 1, 'billing_events' => 1, 'billing_cents' => 200], $this->moneyCounts($business));
    }

    // ---------------------------------------------------------------
    // Finding 5 — denied verify-completion landing
    // ---------------------------------------------------------------

    public function test_denied_pair_through_verify_completion_lands_on_the_existing_referrals_unavailable_page(): void
    {
        $business = World::business();
        [$referrer, $shareToken] = $this->referrerAged($business, '+12125550450');

        // A HELD pending referral for the pair exists when the
        // friend claims: the claim-step gate reads raw states,
        // sees 'pending_redemption', and deliberately does NOT
        // shortcut — verification starts.
        $first = $this->claimService($referrer, '+12125550451');
        $first['referral']->forceFill(['hold' => true, 'held_at' => now(), 'hold_rules' => ['device_signal_reuse']])->save();

        $this->carryFlowCookie($this->get(route('refer.friend', ['token' => $shareToken]))->assertOk());
        $claim = $this->post(route('refer.friend.claim', ['token' => $shareToken]), [
            'first_name' => 'Jamie', 'last_name' => 'Friend', 'phone' => '2125550451', 'consent' => '1',
        ]);
        $claim->assertRedirect(route('refer.verify'));
        $this->carryFlowCookie($claim);
        $this->assertNotEmpty($this->verify->starts, 'verification started: the claim-step shortcut did not fire');

        // The owner denies the held referral between claim and
        // verify. Verify completion now meets a denied pair with
        // no shortcut anywhere in its path.
        $this->assertTrue(app(RedemptionService::class)->denyReferral($first['referral']->fresh()));
        $this->assertSame(Referral::STATE_VOIDED, $first['referral']->fresh()->state);

        $verify = $this->post(route('refer.verify.check'), ['code' => '123456']);
        $verify->assertRedirect(route('refer.redeem.page', ['token' => $first['referral']->redeem_token_encrypted]));

        // What the friend sees: that referral's real unavailable
        // state — not a wall, not a fake success.
        $page = $this->get(route('refer.redeem.page', ['token' => $first['referral']->redeem_token_encrypted]));
        $page->assertOk();
        $page->assertSee('This code is not active');
        $page->assertSee('Please ask the business for help, or check that you scanned the whole code.');
        $page->assertDontSee('Reward redeemed');

        $this->assertSame(['referrals' => 1, 'rewards' => 0, 'billing_events' => 0, 'billing_cents' => 0], $this->moneyCounts($business));
    }

    public function test_governing_resolution_is_denied_after_an_expired_pending_row_frees_its_slot(): void
    {
        // The reviewer's gate-skip shape at the service layer:
        // the pair holds a pending row (raw state still
        // 'pending_redemption', so the claim-step gate skips)
        // whose window has actually run out, PLUS a voided row.
        // The expiry flip frees the pending slot — and the deny
        // must still govern, not a fresh mint.
        Schema::table('referrals', function ($table) {
            $table->dropUnique(['counted_pair_key']);
        });

        $business = World::business();
        [$referrer] = $this->referrerAged($business, '+12125550460');

        $first = $this->claimService($referrer, '+12125550461');
        $friend = World::customer('+12125550461', 'Jamie', 'Friend');
        [$voided] = World::referral($referrer, $friend, ['state' => Referral::STATE_VOIDED]);
        $first['referral']->forceFill(['expires_at' => now()->subMinute()])->save();

        $second = $this->claimService($referrer->fresh(), '+12125550461');

        $this->assertSame('denied', $second['kind']);
        $this->assertSame($voided->id, $second['referral']->id);
        $this->assertSame(Referral::STATE_EXPIRED, $first['referral']->fresh()->state);
        $this->assertSame(['referrals' => 2, 'rewards' => 0, 'billing_events' => 0, 'billing_cents' => 0], $this->moneyCounts($business));
    }
}
