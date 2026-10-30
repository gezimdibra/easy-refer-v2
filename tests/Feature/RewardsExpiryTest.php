<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Jobs\SendRewardExpiryWarningSms;
use App\Jobs\SendRewardReadySms;
use App\Mail\EndProgramMail;
use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\Reward;
use App\Models\SmsMessage;
use App\Models\SmsOptOut;
use App\Services\Billing\SettlementService;
use App\Services\Billing\VisitValuation;
use App\Services\Owner\ActionCenter;
use App\Services\Referral\RedemptionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * M-4 rewards expiry (design pass Section 8.3, test list T1-T34):
 * the 12-month use-by date stamped at mint from availability, the
 * held-clock pause with the release top-up, the earlier-of
 * closure interaction, the T-30 warning that never bills, the
 * named expired states, and the billing boundary proof.
 */
class RewardsExpiryTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsGateway $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsGateway;
        $this->app->instance(SmsGateway::class, $this->sms);
        $this->app->instance(PhoneVerificationGateway::class, new FakePhoneVerificationGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        Mail::fake();
    }

    private int $friendSeq = 7000;

    /** @return array{0: Business, 1: Membership, 2: Referral, 3: Reward, 4: string} */
    private function world(array $businessOverrides = [], array $rewardOverrides = []): array
    {
        $business = World::business($businessOverrides);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+1212555'.str_pad((string) $this->friendSeq++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, [
            'state' => Referral::STATE_REDEEMED,
            'redeemed_at' => now(),
        ]);
        [$reward, $plain] = World::reward($membership, $referral, $rewardOverrides);

        return [$business, $membership, $referral, $reward, $plain];
    }

    /** An available reward with a stamped date, ready to claim. */
    private function availableReward(array $rewardOverrides = [], array $businessOverrides = []): array
    {
        return $this->world($businessOverrides, array_merge([
            'state' => Reward::STATE_AVAILABLE,
            'available_at' => now()->subMonths(2),
            'expires_at' => now()->addMonths(10),
            'notification_state' => 'sent',
        ], $rewardOverrides));
    }

    private function mint(Business $business, Membership $membership): Reward
    {
        $friend = World::customer('+1212555'.str_pad((string) $this->friendSeq++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);
        $result = app(RedemptionService::class)->redeemReferral($referral, null, '127.0.0.1');
        $this->assertSame('redeemed', $result['outcome']);

        return Reward::query()->where('referral_id', $referral->id)->firstOrFail();
    }

    // ---------------------------------------------------------------
    // Mint and stamping (T1-T3, T32)

    public function test_t1_mint_stamps_expiry_twelve_months_from_availability(): void
    {
        foreach ([7, 14, 99] as $configured) {
            $business = World::business(['reward_vesting_days' => $configured]);
            $referrer = World::customer('+12125550999', 'Reg', 'Ular');
            [$membership] = World::membership($business, $referrer);

            $reward = $this->mint($business, $membership);
            $expectedDays = $configured >= 7 && $configured <= 14 ? $configured : 14;

            $this->assertEqualsWithDelta($expectedDays * 86400, abs($reward->available_at->unix() - now()->unix()), 120);
            $this->assertSame(
                $reward->available_at->copy()->addMonths(12)->format('Y-m-d H:i:s'),
                $reward->expires_at->format('Y-m-d H:i:s'),
                "vesting {$configured}: expires_at must be available_at + 12 calendar months",
            );
        }
    }

    public function test_t2_unified_and_split_mint_identically(): void
    {
        $split = World::business(['reward_split' => true]);
        $referrerA = World::customer('+12125550991', 'Reg', 'Ular');
        [$membershipA] = World::membership($split, $referrerA);
        $rewardA = $this->mint($split, $membershipA);

        $unified = World::business(['reward_split' => false, 'referrer_reward_text' => null]);
        $referrerB = World::customer('+12125550992', 'Reg', 'Ular');
        [$membershipB] = World::membership($unified, $referrerB);
        $rewardB = $this->mint($unified, $membershipB);

        foreach ([$rewardA, $rewardB] as $reward) {
            $this->assertSame(
                $reward->available_at->copy()->addMonths(12)->format('Y-m-d H:i:s'),
                $reward->expires_at->format('Y-m-d H:i:s'),
            );
        }
    }

    public function test_t3_migration_backfills_live_rows_only(): void
    {
        [$business, $membership] = [World::business(), null];
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        $mk = function (array $overrides) use ($membership, $business) {
            $friend = World::customer('+1212555'.str_pad((string) $this->friendSeq++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
            [$referral] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()]);
            [$reward] = World::reward($membership, $referral, $overrides);

            return $reward;
        };

        $available = $mk(['state' => Reward::STATE_AVAILABLE, 'available_at' => Carbon::parse('2026-01-10 10:00:00')]);
        $vesting = $mk(['state' => Reward::STATE_PENDING_VESTING, 'available_at' => Carbon::parse('2026-02-01 10:00:00')]);
        $claimed = $mk(['state' => Reward::STATE_CLAIMED, 'available_at' => Carbon::parse('2026-01-10 10:00:00'), 'claimed_at' => now()]);
        $denied = $mk(['state' => Reward::STATE_DENIED, 'available_at' => Carbon::parse('2026-01-10 10:00:00')]);
        $legacy = $mk(['state' => Reward::STATE_AVAILABLE, 'available_at' => null]);

        Schema::table('rewards', function (Blueprint $table) {
            $table->dropIndex(['state', 'expires_at']);
            $table->dropColumn(['expires_at', 'expiry_notice_sent_at']);
        });

        $migration = require database_path('migrations/0001_01_01_000045_add_reward_time_expiry_columns.php');
        $migration->up();

        $this->assertSame('2027-01-10 10:00:00', $available->fresh()->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame('2027-02-01 10:00:00', $vesting->fresh()->expires_at->format('Y-m-d H:i:s'));
        $this->assertNull($claimed->fresh()->expires_at, 'terminal rows keep NULL');
        $this->assertNull($denied->fresh()->expires_at, 'terminal rows keep NULL');
        $this->assertNull($legacy->fresh()->expires_at, 'NULL available_at legacy row untouched');
    }

    public function test_t32_grandfathered_row_shows_its_date_and_cannot_expire_early(): void
    {
        [$business, , , $reward, $plain] = $this->world([], [
            'state' => Reward::STATE_AVAILABLE,
            'available_at' => now()->subMonths(11),
            'expires_at' => now()->subMonths(11)->addMonths(12),
        ]);

        $this->assertFalse($reward->applyTimeExpiry());
        $expected = $business->localTime($reward->expires_at)->format('F j, Y');
        $this->get(route('refer.claim.page', ['token' => $plain]))
            ->assertOk()
            ->assertSee('Good until '.$expected.'.');
    }

    // ---------------------------------------------------------------
    // Transitions (T4-T11)

    public function test_t4_one_second_before_the_date_stays_available_everywhere(): void
    {
        $expiry = Carbon::parse('2027-06-01 12:00:00');
        [$business, , , $reward, $plain] = $this->availableReward(['expires_at' => $expiry]);
        $this->travelTo($expiry->copy()->subSecond());

        $this->assertFalse($reward->applyTimeExpiry());
        $this->assertSame(0, Artisan::call('rewards:expire'));
        $this->assertSame(Reward::STATE_AVAILABLE, $reward->fresh()->state);
        $this->get(route('refer.claim.page', ['token' => $plain]))->assertOk()->assertSee('Show this code to a staff member');
        $this->actingAs(World::owner($business))->get(route('rewards.index'))
            ->assertOk()->assertSee('Good until Jun 1, 2027');
    }

    public function test_t5_at_the_date_flips_once_and_the_sweeper_agrees(): void
    {
        $expiry = Carbon::parse('2027-06-01 12:00:00');
        [, , , $reward] = $this->availableReward(['expires_at' => $expiry]);
        $this->travelTo($expiry->copy());

        $this->assertTrue($reward->applyTimeExpiry());
        $this->assertFalse($reward->fresh()->applyTimeExpiry(), 'second read is a no-op');

        [, , , $reward2] = $this->availableReward(['expires_at' => $expiry->copy()->subDay()]);
        Artisan::call('rewards:expire');
        $this->assertSame(Reward::STATE_EXPIRED, $reward2->fresh()->state);
    }

    public function test_t6_held_reward_past_its_date_does_not_flip(): void
    {
        [$business, , , $reward, $plain] = $this->availableReward([
            'expires_at' => now()->subDay(),
            'hold' => true,
            'held_at' => now()->subDays(2),
            'hold_rules' => ['risk_score'],
        ]);

        $this->assertFalse($reward->applyTimeExpiry());
        Artisan::call('rewards:expire');
        $this->assertSame(Reward::STATE_AVAILABLE, $reward->fresh()->state);
        $this->assertTrue($reward->fresh()->hold);

        $this->get(route('refer.claim.page', ['token' => $plain]))
            ->assertOk()
            ->assertSee('Held for review')
            ->assertDontSee('Good until');
        $this->actingAs(World::owner($business))->get(route('rewards.index', ['filter' => 'held']))
            ->assertOk()->assertSee($reward->reward_text_snapshot);
    }

    public function test_t7_release_tops_up_a_spent_date(): void
    {
        [, , , $reward] = $this->availableReward([
            'expires_at' => now()->subDay(),
            'hold' => true,
            'held_at' => now()->subDays(3),
            'hold_rules' => ['risk_score'],
        ]);

        app(RedemptionService::class)->releaseReward($reward);

        $fresh = $reward->fresh();
        $this->assertFalse($fresh->hold);
        $this->assertGreaterThanOrEqual(now()->addDays(29)->unix(), $fresh->expires_at->unix());
        $this->assertLessThanOrEqual(now()->addDays(30)->addMinutes(5)->unix(), $fresh->expires_at->unix());
        $this->assertSame(Reward::STATE_AVAILABLE, $fresh->state);
    }

    public function test_t8_release_with_time_left_keeps_the_date(): void
    {
        $date = now()->addDays(200)->startOfSecond();
        [, , , $reward] = $this->availableReward([
            'expires_at' => $date,
            'hold' => true,
            'held_at' => now()->subDay(),
            'hold_rules' => ['risk_score'],
        ]);

        app(RedemptionService::class)->releaseReward($reward);

        $this->assertSame($date->format('Y-m-d H:i:s'), $reward->fresh()->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_t9_release_replay_does_not_move_the_date_again(): void
    {
        [, , , $reward] = $this->availableReward([
            'expires_at' => now()->subDay(),
            'hold' => true,
            'held_at' => now()->subDays(3),
            'hold_rules' => ['risk_score'],
        ]);
        $service = app(RedemptionService::class);
        $service->releaseReward($reward);
        $afterFirst = $reward->fresh()->expires_at->format('Y-m-d H:i:s');

        $this->travel(5)->days();
        $service->releaseReward($reward->fresh());

        $this->assertSame($afterFirst, $reward->fresh()->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_t10_claimed_and_denied_never_flip(): void
    {
        foreach ([Reward::STATE_CLAIMED, Reward::STATE_DENIED] as $state) {
            [, , , $reward] = $this->world([], [
                'state' => $state,
                'available_at' => now()->subMonths(13),
                'expires_at' => now()->subMonth(),
                'claimed_at' => $state === Reward::STATE_CLAIMED ? now()->subMonths(2) : null,
            ]);
            $this->assertFalse($reward->applyTimeExpiry());
            Artisan::call('rewards:expire');
            $this->assertSame($state, $reward->fresh()->state);
        }
    }

    public function test_t11_vesting_rows_never_time_flip(): void
    {
        [, , , $reward] = $this->world([], [
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => now()->addDays(3),
            'expires_at' => now()->subDay(),
        ]);

        $this->assertFalse($reward->applyTimeExpiry());
        Artisan::call('rewards:expire');
        $this->assertSame(Reward::STATE_PENDING_VESTING, $reward->fresh()->state);
    }

    // ---------------------------------------------------------------
    // Closure interplay (T12-T14)

    public function test_t12_aged_two_months_at_closure_flips_on_the_closure_clock(): void
    {
        [$business, , , $reward] = $this->availableReward([
            'available_at' => now()->subMonths(2),
            'expires_at' => now()->addMonths(10),
        ]);
        $business->forceFill(['status' => 'closed', 'closed_at' => now()->subDays(29)])->save();

        $this->assertFalse($reward->applyClosureExpiry(), 'inside the window the reward still lives');
        $this->assertFalse($reward->fresh()->applyTimeExpiry(), 'its own date is months away');

        $business->forceFill(['closed_at' => now()->subDays(31)])->save();
        $this->assertTrue($reward->fresh()->applyClosureExpiry());
        $this->assertSame(Reward::STATE_EXPIRED, $reward->fresh()->state);
    }

    public function test_t13_own_date_inside_the_closure_window_wins_and_copy_is_branched(): void
    {
        [$business, , , $reward] = $this->availableReward([
            'available_at' => now()->subMonths(11)->subDays(15),
            'expires_at' => now()->addDays(15),
        ]);
        $business->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

        $this->travel(16)->days();
        $this->assertFalse($business->fresh()->closureWindowEnded());
        $this->assertTrue($reward->fresh()->applyTimeExpiry(), 'the earlier instant governs the flip');

        // The four Section 3.1 sites render the branched clause;
        // the unbranched sentences appear nowhere.
        $freshBusiness = World::business();
        $freshReferrer = World::customer('+12125550993', 'Reg', 'Ular');
        [$freshMembership] = World::membership($freshBusiness, $freshReferrer);
        $freshFriend = World::customer('+12125550793', 'Fri', 'End');
        [$freshReferral] = World::referral($freshMembership, $freshFriend);
        World::reward($freshMembership, $freshReferral, [
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => now()->addDays(7),
            'expires_at' => now()->addDays(7)->addMonths(12),
        ]);
        $this->actingAs(World::owner($freshBusiness))->get(route('settings.account'))
            ->assertOk()
            ->assertSee('stay good for 30 days after you end, or until their own use by date if that comes first')
            ->assertSee('stay good until 30 days after you end, or their own use by date if that comes first')
            ->assertDontSee('stay good for 30 days. Your account stays open')
            ->assertDontSee('stay good until 30 days after you end. Any');

        $this->get(route('faq'))
            ->assertOk()
            ->assertSee('stay good for 30 days, or until their own use by date if that comes first')
            ->assertDontSee('stay good for 30 days. Your account stays open');

        $mail = new EndProgramMail($freshBusiness, 'zero', 0);
        $html = $mail->render();
        $this->assertStringContainsString('or until a reward\'s own use by date if that comes first', $html);
        $this->assertDoesNotMatchRegularExpression('/stay good until [^,]*\. After that/', $html, 'the unbranched email sentence is gone');
    }

    public function test_t14_held_at_closure_flips_on_the_closure_clock_only(): void
    {
        [$business, , , $reward] = $this->availableReward([
            'expires_at' => now()->subDay(),
            'hold' => true,
            'held_at' => now()->subDays(2),
            'hold_rules' => ['risk_score'],
        ]);
        $business->forceFill(['status' => 'closed', 'closed_at' => now()->subDays(29)])->save();

        $this->assertFalse($reward->applyTimeExpiry(), 'time clock stays paused under hold even at a closed business');
        $this->assertFalse($reward->applyClosureExpiry());

        $business->forceFill(['closed_at' => now()->subDays(31)])->save();
        $this->assertTrue($reward->fresh()->applyClosureExpiry(), 'closure is the only clock running there');
    }

    // ---------------------------------------------------------------
    // Claim paths (T15-T18)

    public function test_t15_claim_reward_at_expiry_returns_expired_and_claims_nothing(): void
    {
        [, , , $reward] = $this->availableReward(['expires_at' => now()->subMinute()]);

        $result = app(RedemptionService::class)->claimReward($reward, null, '127.0.0.1');

        $this->assertSame('expired', $result['outcome']);
        $fresh = $reward->fresh();
        $this->assertSame(Reward::STATE_EXPIRED, $fresh->state);
        $this->assertNull($fresh->claimed_at);
    }

    public function test_t16_staff_confirm_after_expiry_claims_nothing(): void
    {
        // The race inside the nonce's own 30-minute life: the
        // screen renders while the reward is claimable, the
        // reward's date passes, then the press lands.
        [, , , $reward, $plain] = $this->availableReward(['expires_at' => now()->addMinutes(10)]);

        $show = $this->get(route('redeem.show', ['token' => $plain]))->assertOk();
        preg_match('/name="nonce" value="([^"]+)"/', $show->getContent(), $m);
        $this->assertNotEmpty($m, 'staff reward screen carries a nonce');

        $this->travel(15)->minutes();
        $this->post(route('redeem.confirm', ['token' => $plain]), ['nonce' => $m[1]]);

        $fresh = $reward->fresh();
        $this->assertSame(Reward::STATE_EXPIRED, $fresh->state);
        $this->assertNull($fresh->claimed_at);
    }

    public function test_t17_claim_page_names_time_expiry_and_keeps_closure_generic(): void
    {
        [$business, , , $reward, $plain] = $this->availableReward(['expires_at' => now()->subDay()]);
        $date = $business->localTime($reward->expires_at)->format('F j, Y');

        $this->get(route('refer.claim.page', ['token' => $plain]))
            ->assertOk()
            ->assertSee('This reward ran out on '.$date.'.')
            ->assertSee('Your reward: '.$reward->reward_text_snapshot.'.')
            ->assertSee('If you think this is a mistake, contact the store.');

        [$closedBusiness, , , $reward2, $plain2] = $this->availableReward(['expires_at' => now()->addMonths(6)]);
        $closedBusiness->forceFill(['status' => 'closed', 'closed_at' => now()->subDays(31)])->save();

        $closurePage = $this->get(route('refer.claim.page', ['token' => $plain2]));
        $closurePage->assertNotFound();
        $this->assertStringContainsString('Something is not right with that link', $closurePage->getContent());
        $this->assertStringNotContainsString('ran out on', $closurePage->getContent());
    }

    public function test_t18_staff_page_names_time_expiry_and_keeps_closure_generic(): void
    {
        [$business, , , $reward, $plain] = $this->availableReward(['expires_at' => now()->subDay()]);
        $date = $business->localTime($reward->expires_at)->format('F j, Y');

        $this->get(route('redeem.show', ['token' => $plain]))
            ->assertOk()
            ->assertSee('This reward expired on '.$date.'.');

        [$closedBusiness, , , , $plain2] = $this->availableReward(['expires_at' => now()->addMonths(6)]);
        $closedBusiness->forceFill(['status' => 'closed', 'closed_at' => now()->subDays(31)])->save();

        $closureStaff = $this->get(route('redeem.show', ['token' => $plain2]));
        $closureStaff->assertNotFound();
        $this->assertStringContainsString('This code is not active', $closureStaff->getContent());
        $this->assertStringNotContainsString('This reward expired on', $closureStaff->getContent());
    }

    // ---------------------------------------------------------------
    // Billing boundary (T19-T21)

    private function moneySnapshot(Business $business): string
    {
        $fresh = $business->fresh();
        $events = BillingEvent::query()->where('business_id', $business->id)->orderBy('id')
            ->get()->map(fn (BillingEvent $e) => $e->getAttributes())->all();
        $settle = (new VisitValuation)->settle($fresh);
        $service = app(SettlementService::class);
        $forecast = $service->forecastPreview($fresh);
        $accrued = $service->accruedPreview($fresh);
        $text = $service->textFigures($fresh, now());

        return json_encode([$events, $settle, $forecast, $accrued, $text, $fresh->free_visits_remaining]);
    }

    /** @return list<Reward> */
    private function redeemedRewardsWithEvents(Business $business, Membership $membership, int $count): array
    {
        $rewards = [];
        for ($i = 0; $i < $count; $i++) {
            $friend = World::customer('+1212555'.str_pad((string) $this->friendSeq++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
            [$referral] = World::referral($membership, $friend, [
                'state' => Referral::STATE_REDEEMED,
                'redeemed_at' => now(),
            ]);
            $referral->forceFill(['created_at' => now()->subMinutes(10000 - $i)])->save();
            BillingEvent::create([
                'business_id' => $business->id,
                'referral_id' => $referral->id,
                'amount_cents' => 200,
                'coverage' => 'billable',
            ]);
            [$reward] = World::reward($membership, $referral->fresh(), [
                'state' => Reward::STATE_AVAILABLE,
                'available_at' => now()->subMonths(13),
                'expires_at' => now()->subDay(),
            ]);
            $rewards[] = $reward;
        }

        return $rewards;
    }

    public function test_t19_expiry_moves_no_money_figure_anywhere(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        // 55 visits: the settlement walk produces free_credit
        // outcomes from the allowance logic, billable outcomes,
        // and over_cap outcomes past the $99 cap.
        $rewards = $this->redeemedRewardsWithEvents($business, $membership, 55);

        $before = $this->moneySnapshot($business);
        $this->assertStringContainsString('over_cap', $before);
        $this->assertStringContainsString('billable', $before);

        foreach ($rewards as $reward) {
            $this->assertTrue($reward->applyTimeExpiry());
        }

        $this->assertSame($before, $this->moneySnapshot($business), 'expiry must be byte-identical on every money figure');
    }

    public function test_t20_expired_visits_are_not_unbilled_a_month_later(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $rewards = $this->redeemedRewardsWithEvents($business, $membership, 7);

        foreach ($rewards as $reward) {
            $reward->applyTimeExpiry();
        }
        $afterExpiry = $this->moneySnapshot($business);

        $this->travel(40)->days();
        $this->assertSame($afterExpiry, $this->moneySnapshot($business), 'a later settlement re-run must not un-bill an expired reward\'s visit');
    }

    public function test_t21_the_warning_creates_no_billing_artifact(): void
    {
        [$business, , , $reward] = $this->availableReward(['expires_at' => now()->addDays(10)]);
        $before = $this->moneySnapshot($business);
        $eventsBefore = BillingEvent::query()->count();

        (new SendRewardExpiryWarningSms($reward->id))->handle($this->sms);

        $this->assertCount(1, $this->sms->sent);
        $message = SmsMessage::query()->where('purpose', 'reward_expiry_warning')->sole();
        $this->assertSame('transactional', $message->consent_basis);
        $this->assertSame($eventsBefore, BillingEvent::query()->count());
        $this->assertSame($before, $this->moneySnapshot($business), 'no statement, forecast, or text figure may see the warning');
    }

    // ---------------------------------------------------------------
    // Notices (T22-T26)

    public function test_t22_reward_ready_text_carries_each_rewards_own_date(): void
    {
        [$business, , , $rewardA] = $this->availableReward([
            'expires_at' => Carbon::parse('2027-03-05 15:00:00'),
            'notification_state' => 'queued',
        ]);
        [, , , $rewardB] = $this->world([], [
            'state' => Reward::STATE_AVAILABLE,
            'available_at' => now()->subDay(),
            'expires_at' => Carbon::parse('2027-09-19 15:00:00'),
            'notification_state' => 'queued',
        ]);

        (new SendRewardReadySms($rewardA->id))->handle($this->sms);
        (new SendRewardReadySms($rewardB->id))->handle($this->sms);

        $this->assertCount(2, $this->sms->sent);
        $bodies = collect($this->sms->sent)->pluck('body')->all();
        $withA = collect($bodies)->first(fn ($b) => str_contains($b, 'Good until Mar 5, 2027.'));
        $withB = collect($bodies)->first(fn ($b) => str_contains($b, 'Good until Sep 19, 2027.'));
        $this->assertNotNull($withA, 'first text carries the first reward\'s date');
        $this->assertNotNull($withB, 'second text carries the second reward\'s date');
        $this->assertStringNotContainsString('Sep 19', $withA, 'no cross-talk between rewards');
        // The date clause is part of the base body: it precedes
        // any reshare appendage.
        if (str_contains($withA, 'Share your link')) {
            $this->assertLessThan(strpos($withA, 'Share your link'), strpos($withA, 'Good until'));
        }
    }

    public function test_t23_warning_sends_once_inside_the_window_and_suppresses_the_rest(): void
    {
        // Inside the window: sends, stamps, and a rerun is safe.
        [, , , $reward] = $this->availableReward(['expires_at' => now()->addDays(10)]);
        (new SendRewardExpiryWarningSms($reward->id))->handle($this->sms);
        $this->assertCount(1, $this->sms->sent);
        $this->assertStringContainsString('It runs out on ', $this->sms->sent[0]['body']);
        $this->assertNotNull($reward->fresh()->expiry_notice_sent_at);
        (new SendRewardExpiryWarningSms($reward->id))->handle($this->sms);
        $this->assertCount(1, $this->sms->sent, 'exactly once per reward');

        // Held: transient, sends nothing, marker unstamped.
        [, , , $held] = $this->availableReward([
            'expires_at' => now()->addDays(10),
            'hold' => true, 'held_at' => now(), 'hold_rules' => ['risk_score'],
        ]);
        (new SendRewardExpiryWarningSms($held->id))->handle($this->sms);
        $this->assertCount(1, $this->sms->sent);
        $this->assertNull($held->fresh()->expiry_notice_sent_at);

        // Claimed: terminal suppression stamps the marker.
        [, , , $claimed] = $this->world([], [
            'state' => Reward::STATE_CLAIMED, 'claimed_at' => now(),
            'expires_at' => now()->addDays(10),
        ]);
        (new SendRewardExpiryWarningSms($claimed->id))->handle($this->sms);
        $this->assertCount(1, $this->sms->sent);
        $this->assertNotNull($claimed->fresh()->expiry_notice_sent_at);

        // Expired and denied suppress the same way.
        foreach ([Reward::STATE_EXPIRED, Reward::STATE_DENIED] as $state) {
            [, , , $terminal] = $this->world([], ['state' => $state, 'expires_at' => now()->addDays(10)]);
            (new SendRewardExpiryWarningSms($terminal->id))->handle($this->sms);
            $this->assertNotNull($terminal->fresh()->expiry_notice_sent_at);
        }
        $this->assertCount(1, $this->sms->sent);

        // Opted out: nothing sends, marker stamped.
        [$biz, $membership, , $optedOut] = $this->availableReward(['expires_at' => now()->addDays(10)]);
        $phone = $optedOut->membership->customer->phone_e164;
        SmsOptOut::create(['phone_e164' => $phone, 'opted_out_at' => now(), 'source' => 'stop']);
        (new SendRewardExpiryWarningSms($optedOut->id))->handle($this->sms);
        $this->assertCount(1, $this->sms->sent);
        $this->assertNotNull($optedOut->fresh()->expiry_notice_sent_at);

        // Outside the window: the daily command does not select it.
        [, , , $far] = $this->availableReward(['expires_at' => now()->addDays(60)]);
        $sentBefore = count($this->sms->sent);
        Artisan::call('rewards:warn-expiring');
        $this->assertNull($far->fresh()->expiry_notice_sent_at);
        $this->assertSame($sentBefore, count($this->sms->sent), 'the sweep sent nothing new');
    }

    public function test_t24_released_after_warning_is_not_retexted_and_shows_the_new_date(): void
    {
        [, , , $reward, $plain] = $this->availableReward([
            'expires_at' => now()->subDay(),
            'hold' => true, 'held_at' => now()->subDays(5), 'hold_rules' => ['risk_score'],
            'expiry_notice_sent_at' => now()->subDays(20),
        ]);

        app(RedemptionService::class)->releaseReward($reward);
        $fresh = $reward->fresh();
        $this->assertNotNull($fresh->expiry_notice_sent_at, 'the one-text record stands');

        (new SendRewardExpiryWarningSms($reward->id))->handle($this->sms);
        $this->assertCount(0, $this->sms->sent, 'no second text correcting the date');

        $expected = $fresh->business->localTime($fresh->expires_at)->format('F j, Y');
        $this->get(route('refer.claim.page', ['token' => $plain]))
            ->assertOk()
            ->assertSee('Good until '.$expected.'.', false);
    }

    public function test_t25_owner_resend_refuses_a_time_expired_reward(): void
    {
        [$business, , , $reward] = $this->availableReward([
            'expires_at' => now()->subDay(),
            'notification_state' => 'failed',
        ]);

        $this->actingAs(World::owner($business))
            ->post(route('rewards.resend', $reward))
            ->assertSessionHasErrors('reward');

        $this->assertCount(0, $this->sms->sent);
        $this->assertSame('failed', $reward->fresh()->notification_state);
        $this->assertSame(Reward::STATE_EXPIRED, $reward->fresh()->state);
    }

    public function test_t26_reward_ready_job_across_the_boundary_sends_nothing(): void
    {
        [, , , $reward] = $this->availableReward([
            'expires_at' => now()->subHour(),
            'notification_state' => 'queued',
        ]);

        (new SendRewardReadySms($reward->id))->handle($this->sms);

        $this->assertCount(0, $this->sms->sent);
        $fresh = $reward->fresh();
        $this->assertSame(Reward::STATE_EXPIRED, $fresh->state);
        $this->assertSame('suppressed', $fresh->notification_state);
    }

    // ---------------------------------------------------------------
    // Surfaces (T27-T31)

    public function test_t27_dashboard_tile_drops_the_expired_reward_in_the_same_read(): void
    {
        [$business, $membership] = [World::business(), null];
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $mk = function (array $overrides) use ($membership) {
            $friend = World::customer('+1212555'.str_pad((string) $this->friendSeq++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
            [$referral] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()]);
            [$reward] = World::reward($membership, $referral, $overrides);

            return $reward;
        };
        $mk(['state' => Reward::STATE_AVAILABLE, 'available_at' => now()->subMonth(), 'expires_at' => now()->addMonths(6)]);
        $expiring = $mk(['state' => Reward::STATE_AVAILABLE, 'available_at' => now()->subMonths(13), 'expires_at' => now()->subDay()]);
        $this->assertSame(Reward::STATE_AVAILABLE, $expiring->state, 'stored state is stale before the read');

        $response = $this->actingAs(World::owner($business))->get(route('dashboard'))->assertOk();
        $tiles = $response->viewData('tiles');
        $counters = $response->viewData('counters');
        $this->assertSame(1, $tiles['rewards_waiting'], 'the expired reward leaves the tile at the read that expires it');
        $this->assertSame(2, $counters['rewards_issued'], 'issued is history: an expired reward was issued');
        $this->assertSame(Reward::STATE_EXPIRED, $expiring->fresh()->state);
    }

    public function test_t28_expired_tab_partitions_against_all(): void
    {
        [$business, $membership] = [World::business(), null];
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $mk = function (array $overrides) use ($membership) {
            $friend = World::customer('+1212555'.str_pad((string) $this->friendSeq++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
            [$referral] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()]);
            [$reward] = World::reward($membership, $referral, $overrides);

            return $reward;
        };
        $mk(['state' => Reward::STATE_AVAILABLE, 'expires_at' => now()->addMonths(6)]);
        $mk(['state' => Reward::STATE_PENDING_VESTING, 'available_at' => now()->addDays(5), 'expires_at' => now()->addMonths(12)]);
        $mk(['state' => Reward::STATE_CLAIMED, 'claimed_at' => now()]);
        $expired = $mk(['state' => Reward::STATE_EXPIRED, 'expires_at' => now()->subDay()]);
        $mk(['state' => Reward::STATE_EXPIRED, 'expires_at' => now()->subDay(), 'hold' => true, 'held_at' => now(), 'hold_rules' => ['risk_score']]);

        $response = $this->actingAs(World::owner($business))
            ->get(route('rewards.index', ['filter' => 'expired']))
            ->assertOk()
            ->assertSee('Expired (1)');
        $counts = $response->viewData('counts');
        $this->assertSame(5, $counts['all']);
        $this->assertSame(1, $counts['available']);
        $this->assertSame(1, $counts['pending_vesting']);
        $this->assertSame(1, $counts['claimed']);
        $this->assertSame(1, $counts['held'], 'held closure-expired rows stay under Held');
        $this->assertSame(1, $counts['expired']);
        $listed = $response->viewData('rewards');
        $this->assertSame([$expired->id], $listed->pluck('id')->all());
    }

    public function test_t29_settings_preview_states_all_three_clocks(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->get(route('settings.rewards'))
            ->assertOk()
            ->assertSee('days to visit')
            ->assertSee('reward is ready at the visit.')
            ->assertSee('days after the visit')
            ->assertSee('stays good for 12 months after it is ready.');

        $this->actingAs($owner)->get(route('onboarding.step', ['step' => 'rewards']))
            ->assertOk()
            ->assertSee('stays good for 12 months after it is ready.');
    }

    public function test_t30_share_page_excludes_a_just_expired_reward(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);

        // Enroll through the real flow (the ShareRewardBlockTest
        // pattern) so the share page session exists.
        $carry = function ($response) {
            foreach ($response->headers->getCookies() as $cookie) {
                if ($cookie->getName() === 'er2_flow') {
                    $this->withUnencryptedCookie('er2_flow', $cookie->getValue());
                }
            }
        };
        $carry($this->get(route('refer.landing', ['token' => $qrToken]))->assertOk());
        $carry($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '+12125550111', 'consent' => '1',
        ])->assertRedirect(route('refer.verify')));
        $carry($this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect(route('refer.share')));
        $membership = Membership::query()->where('business_id', $business->id)->latest('id')->firstOrFail();

        $friendA = World::customer('+12125550771', 'Fri', 'End');
        [$referralA] = World::referral($membership, $friendA, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()]);
        World::reward($membership, $referralA, [
            'state' => Reward::STATE_AVAILABLE,
            'available_at' => now()->subMonths(13),
            'expires_at' => now()->subDay(),
            'reward_text_snapshot' => 'EXPIRED-UNIQUE-TEXT',
        ]);
        $friendB = World::customer('+12125550772', 'Fri', 'End');
        [$referralB] = World::referral($membership, $friendB, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()]);
        [, $plainB] = World::reward($membership, $referralB, [
            'state' => Reward::STATE_AVAILABLE,
            'available_at' => now()->subDay(),
            'expires_at' => Carbon::parse('2027-08-20 15:00:00'),
            'reward_text_snapshot' => 'SIBLING-UNIQUE-TEXT',
        ]);

        $html = $this->get(route('refer.share'))->assertOk()->getContent();
        $this->assertStringNotContainsString('EXPIRED-UNIQUE-TEXT', $html);
        $this->assertStringContainsString('SIBLING-UNIQUE-TEXT', $html);
        $this->assertStringContainsString('Good until August 20, 2027.', $html);
    }

    public function test_t31_one_instant_renders_one_business_local_date_per_surface(): void
    {
        // 00:30 UTC is the previous evening in New York (EDT).
        $instant = Carbon::parse('2027-03-15 00:30:00');
        [$business, , , $reward, $plain] = $this->availableReward([
            'expires_at' => $instant,
            'notification_state' => 'queued',
        ]);
        $this->travelTo(Carbon::parse('2027-03-01 12:00:00'));

        $this->get(route('refer.claim.page', ['token' => $plain]))
            ->assertOk()
            ->assertSee('Good until March 14, 2027.');

        (new SendRewardReadySms($reward->id))->handle($this->sms);
        $this->assertCount(1, $this->sms->sent);
        $this->assertStringContainsString('Good until Mar 14, 2027.', $this->sms->sent[0]['body']);

        $this->actingAs(World::owner($business))->get(route('rewards.index'))
            ->assertOk()
            ->assertSee('Good until Mar 14, 2027');
    }

    // ---------------------------------------------------------------
    // Absence pins (T33) and the claim-page visible-text sweep (T34)

    public function test_t33_absence_pins_and_print_surfaces_untouched(): void
    {
        [$business, , , $reward, $plain] = $this->availableReward(['expires_at' => now()->addDays(90)]);

        $claimHtml = $this->get(route('refer.claim.page', ['token' => $plain]))->assertOk()->getContent();
        $this->assertStringNotContainsString('never expires', $claimHtml);
        $this->assertStringNotContainsString('no expiry', $claimHtml);

        $faqHtml = $this->get(route('faq'))->assertOk()->getContent();
        $this->assertStringNotContainsString('never expires', $faqHtml);
        $this->assertStringContainsString('How long do rewards last?', $faqHtml);
        $this->assertStringContainsString('stays good for 12 months from the day it is ready', $faqHtml);

        // The unbranched closure sentences (pass Section 3.1)
        // survive nowhere in the shipped copy sources. NOTE:
        // [E-HELD]'s held-line tail was a fifth site the pass
        // census did not list; the D2 fix branches it
        // conditionally ([M4-ACCT-03], pinned in T35), so its
        // original form legitimately remains in the source as
        // the branch that renders only when it is true.
        $sources = [
            file_get_contents(resource_path('views/owner/settings/account.blade.php')),
            file_get_contents(resource_path('views/emails/end-program.blade.php')),
            file_get_contents(app_path('Http/Controllers/MarketingController.php')),
        ];
        foreach ($sources as $source) {
            $this->assertStringNotContainsString('stay good for 30 days.', $source);
            $this->assertStringNotContainsString('stay good until 30 days after you end. Any', $source);
        }
    }

    public function test_t34_claim_page_visible_text_per_state(): void
    {
        // Ready: carries the date line and the instruction.
        [, , , , $plainReady] = $this->availableReward(['expires_at' => Carbon::parse('2027-05-10 12:00:00')]);
        $this->travelTo(Carbon::parse('2027-01-01 12:00:00'));
        $ready = $this->get(route('refer.claim.page', ['token' => $plainReady]))->assertOk()->getContent();
        $this->assertStringContainsString('Good until May 10, 2027.', $ready);
        $this->assertStringContainsString('Show this code to a staff member', $ready);

        // Waiting: no use-by date, no ready instruction.
        [, , , , $plainWaiting] = $this->world([], [
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => now()->addDays(5),
            'expires_at' => now()->addDays(5)->addMonths(12),
        ]);
        $waiting = $this->get(route('refer.claim.page', ['token' => $plainWaiting]))->assertOk()->getContent();
        $this->assertStringContainsString('Your reward is on its way', $waiting);
        $this->assertStringNotContainsString('Good until', $waiting);

        // Held: no date at all.
        [, , , , $plainHeld] = $this->availableReward([
            'expires_at' => now()->addDays(30),
            'hold' => true, 'held_at' => now(), 'hold_rules' => ['risk_score'],
        ]);
        $held = $this->get(route('refer.claim.page', ['token' => $plainHeld]))->assertOk()->getContent();
        $this->assertStringContainsString('Held for review', $held);
        $this->assertStringNotContainsString('Good until', $held);

        // Claimed: the receipt carries no use-by date.
        [, , , , $plainClaimed] = $this->world([], [
            'state' => Reward::STATE_CLAIMED, 'claimed_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);
        $claimed = $this->get(route('refer.claim.page', ['token' => $plainClaimed]))->assertOk()->getContent();
        $this->assertStringNotContainsString('Good until', $claimed);

        // Denied: no date.
        [, , , , $plainDenied] = $this->world([], [
            'state' => Reward::STATE_DENIED, 'expires_at' => now()->addDays(30),
        ]);
        $denied = $this->get(route('refer.claim.page', ['token' => $plainDenied]))->assertOk()->getContent();
        $this->assertStringContainsString('was not approved', $denied);
        $this->assertStringNotContainsString('Good until', $denied);

        // Expired: the named state, the date in the ran-out line only.
        [, , , , $plainExpired] = $this->availableReward(['expires_at' => now()->subDay()]);
        $expired = $this->get(route('refer.claim.page', ['token' => $plainExpired]))->assertOk()->getContent();
        $this->assertStringContainsString('This reward ran out on ', $expired);
        $this->assertStringNotContainsString('Show this code to a staff member', $expired);
    }

    // ---------------------------------------------------------------
    // D2 fix: the fifth closure string ([E-HELD] tail) and the
    // same view's exact-date closed lines ([E-08] / [E-08b])

    public function test_t35_held_line_branches_only_when_a_held_rewards_own_date_falls_inside_the_window(): void
    {
        // Inside: a held reward aged over 11 months whose own
        // date falls inside the 30 days after an ending today
        // renders the branched [M4-ACCT-03] tail.
        [$insideBusiness] = $this->availableReward([
            'available_at' => now()->subMonths(11)->subDays(15),
            'expires_at' => now()->addDays(15),
            'hold' => true, 'held_at' => now()->subDays(3), 'hold_rules' => ['risk_score'],
        ]);
        $inside = $this->actingAs(World::owner($insideBusiness))->get(route('settings.account'))->assertOk();
        $inside->assertSee('Anything you release stays claimable until 30 days after you end, or until its own use by date if that comes first.');
        $inside->assertDontSee('Anything you release stays claimable until 30 days after you end.');
        // Non-closure state: the active page carries no closed
        // line at all; only the held tail changed.
        $inside->assertDontSee('Your program ended on');

        // Outside: the held reward's own date falls after the
        // window, so the original tail is true for every reward
        // it covers and renders unchanged.
        [$outsideBusiness] = $this->availableReward([
            'expires_at' => now()->addDays(90),
            'hold' => true, 'held_at' => now()->subDays(3), 'hold_rules' => ['risk_score'],
        ]);
        $outside = $this->actingAs(World::owner($outsideBusiness))->get(route('settings.account'))->assertOk();
        $outside->assertSee('Anything you release stays claimable until 30 days after you end.');
        $outside->assertDontSee('or until its own use by date');
        $outside->assertDontSee('Your program ended on');

        // A NON-held reward inside the window does not branch
        // the held tail: the tail covers only what the owner
        // releases, and the held reward here is dated outside.
        [$mixedBusiness, $mixedMembership] = $this->availableReward([
            'expires_at' => now()->addDays(90),
            'hold' => true, 'held_at' => now()->subDays(3), 'hold_rules' => ['risk_score'],
        ]);
        $friend = World::customer('+1212555'.str_pad((string) $this->friendSeq++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
        [$referral] = World::referral($mixedMembership, $friend, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()]);
        World::reward($mixedMembership, $referral, [
            'state' => Reward::STATE_AVAILABLE,
            'available_at' => now()->subMonths(11)->subDays(15),
            'expires_at' => now()->addDays(15),
        ]);
        $mixed = $this->actingAs(World::owner($mixedBusiness))->get(route('settings.account'))->assertOk();
        $mixed->assertSee('Anything you release stays claimable until 30 days after you end.');
        $mixed->assertDontSee('or until its own use by date');
    }

    public function test_t36_closed_lines_branch_only_when_a_live_rewards_own_date_falls_inside_the_actual_window(): void
    {
        // [E-08] inside the window, own date earlier than the
        // named closure date: the branched [M4-ACCT-04] form.
        [$insideBusiness] = $this->availableReward([
            'available_at' => now()->subMonths(11)->subDays(15),
            'expires_at' => now()->addDays(15),
        ]);
        $insideBusiness->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        $insideDate = $insideBusiness->localTime($insideBusiness->closed_at->copy()->addDays(30))->format('M j, Y');
        $inside = $this->actingAs(World::owner($insideBusiness))->get(route('settings.account'))->assertOk();
        $inside->assertSee('Rewards your customers earned stay claimable until '.$insideDate.', or until their own use by date if that comes first.');
        $inside->assertDontSee('stay claimable until '.$insideDate.'.');

        // [E-08] inside the window, every live reward dated
        // after the closure date: the original exact-date form.
        [$outsideBusiness] = $this->availableReward(['expires_at' => now()->addDays(90)]);
        $outsideBusiness->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        $outsideDate = $outsideBusiness->localTime($outsideBusiness->closed_at->copy()->addDays(30))->format('M j, Y');
        $outside = $this->actingAs(World::owner($outsideBusiness))->get(route('settings.account'))->assertOk();
        $outside->assertSee('Rewards your customers earned stay claimable until '.$outsideDate.'.');
        $outside->assertDontSee('or until their own use by date');

        // [E-08b] past the window stands unbranched: closure
        // expiry has ended claimability for every live reward,
        // so the ended form is true whatever the own dates were.
        [$endedBusiness] = $this->availableReward(['expires_at' => now()->addDays(90)]);
        $endedBusiness->forceFill(['status' => 'closed', 'closed_at' => now()->subDays(31)])->save();
        $endedDate = $endedBusiness->localTime($endedBusiness->closed_at->copy()->addDays(30))->format('M j, Y');
        $ended = $this->actingAs(World::owner($endedBusiness))->get(route('settings.account'))->assertOk();
        $ended->assertSee('The 30 days for claiming rewards ended on '.$endedDate.'. Rewards from the program can no longer be claimed.');
        $ended->assertDontSee('stay claimable until');
    }

    // ---------------------------------------------------------------
    // DASH-13 fix: the dashboard closed banner's in-window line
    // is [E-08]'s exact-date twin and receives the same
    // conditional branch ([M4-DASH-01], the [M4-ACCT-04] pattern)

    public function test_t37_dashboard_closed_banner_branches_only_when_a_live_rewards_own_date_falls_inside_the_actual_window(): void
    {
        // Inside the window, own date earlier than the named
        // closure date: the branched [M4-DASH-01] form.
        [$insideBusiness] = $this->availableReward([
            'available_at' => now()->subMonths(11)->subDays(15),
            'expires_at' => now()->addDays(15),
        ]);
        $insideBusiness->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        $insideDate = $insideBusiness->localTime($insideBusiness->closed_at->copy()->addDays(30))->format('M j, Y');
        $inside = $this->actingAs(World::owner($insideBusiness))->get(route('dashboard'))->assertOk();
        $inside->assertSee('Your program has ended. You can still sign in and view your history. Rewards your customers earned stay claimable until '.$insideDate.', or until their own use by date if that comes first. New enrollments and your QR code are off.');
        $inside->assertDontSee('stay claimable until '.$insideDate.'.');

        // Inside the window, every live reward dated after the
        // closure date: the original [DASH-13 closed banner v3]
        // exact-date form renders unchanged.
        [$outsideBusiness] = $this->availableReward(['expires_at' => now()->addDays(90)]);
        $outsideBusiness->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        $outsideDate = $outsideBusiness->localTime($outsideBusiness->closed_at->copy()->addDays(30))->format('M j, Y');
        $outside = $this->actingAs(World::owner($outsideBusiness))->get(route('dashboard'))->assertOk();
        $outside->assertSee('Your program has ended. You can still sign in and view your history. Rewards your customers earned stay claimable until '.$outsideDate.'. New enrollments and your QR code are off.');
        $outside->assertDontSee('or until their own use by date');

        // Inside the window, a dateless legacy reward only: the
        // original exact-date form is true for it (no stamped
        // date can come first) and renders unchanged.
        [$legacyBusiness] = $this->availableReward(['expires_at' => null]);
        $legacyBusiness->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        $legacyDate = $legacyBusiness->localTime($legacyBusiness->closed_at->copy()->addDays(30))->format('M j, Y');
        $legacy = $this->actingAs(World::owner($legacyBusiness))->get(route('dashboard'))->assertOk();
        $legacy->assertSee('stay claimable until '.$legacyDate.'.');
        $legacy->assertDontSee('or until their own use by date');

        // Past the window the ended form stands unbranched, as
        // on the account page ([E-08b] examined, not amended):
        // closure expiry has ended claimability for every live
        // reward whatever its own date was.
        [$endedBusiness] = $this->availableReward(['expires_at' => now()->addDays(90)]);
        $endedBusiness->forceFill(['status' => 'closed', 'closed_at' => now()->subDays(31)])->save();
        $endedDate = $endedBusiness->localTime($endedBusiness->closed_at->copy()->addDays(30))->format('M j, Y');
        $ended = $this->actingAs(World::owner($endedBusiness))->get(route('dashboard'))->assertOk();
        $ended->assertSee('Your program has ended. You can still sign in and view your history. The 30 days for claiming rewards ended on '.$endedDate.'. New enrollments and your QR code are off.');
        $ended->assertDontSee('stay claimable until');

        // No closure state: the banner is absent entirely, as
        // today; an active business with a live reward dated
        // inside any prospective window sees no closure copy.
        [$activeBusiness] = $this->availableReward(['expires_at' => now()->addDays(15)]);
        $active = $this->actingAs(World::owner($activeBusiness))->get(route('dashboard'))->assertOk();
        $active->assertDontSee('Your program has ended');
        $active->assertDontSee('stay claimable until');
    }

    // ---------------------------------------------------------------
    // Action Center row [M4-AC-01]

    // ---------------------------------------------------------------
    // Two-key fix pins (P1-P3, m4-twokey-review Items 2, 4, 7):
    // the both-due pending_vesting state (vesting elapsed AND
    // use-by date past) and the dateless legacy expired row —
    // the states T15/T26/T17/T18 never constructed because they
    // start from available or dated rows.

    public function test_p1_claim_reward_from_pending_vesting_both_due_expires_instead_of_claiming(): void
    {
        [, , , $reward] = $this->world([], [
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => now()->subMonths(13),
            'expires_at' => now()->subMonth(),
            'hold' => false,
        ]);

        $result = app(RedemptionService::class)->claimReward($reward, null, '127.0.0.1');

        $this->assertSame('expired', $result['outcome']);
        $fresh = $reward->fresh();
        $this->assertSame(Reward::STATE_EXPIRED, $fresh->state);
        $this->assertNull($fresh->claimed_at);
    }

    public function test_p2_reward_ready_job_on_both_due_pending_vesting_sends_nothing_and_suppresses(): void
    {
        [, , , $reward] = $this->world([], [
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => now()->subMonths(13),
            'expires_at' => now()->subMonth(),
            'hold' => false,
            'notification_state' => 'queued',
        ]);

        (new SendRewardReadySms($reward->id))->handle($this->sms);

        $this->assertCount(0, $this->sms->sent);
        $fresh = $reward->fresh();
        $this->assertSame(Reward::STATE_EXPIRED, $fresh->state);
        $this->assertSame('suppressed', $fresh->notification_state);
    }

    public function test_p3_claim_page_dateless_expired_reward_is_generic_404_never_500_and_staff_agrees(): void
    {
        [, , , , $plain] = $this->world([], [
            'state' => Reward::STATE_EXPIRED,
            'available_at' => now()->subMonths(2),
            'expires_at' => null,
        ]);

        $claim = $this->get(route('refer.claim.page', ['token' => $plain]));
        $claim->assertNotFound();
        $this->assertStringContainsString('Something is not right with that link', $claim->getContent());
        $this->assertStringNotContainsString('ran out on', $claim->getContent());

        [, , , , $plainStaff] = $this->world([], [
            'state' => Reward::STATE_EXPIRED,
            'available_at' => now()->subMonths(2),
            'expires_at' => null,
        ]);

        $staff = $this->get(route('redeem.show', ['token' => $plainStaff]));
        $staff->assertNotFound();
        $this->assertStringContainsString('This code is not active', $staff->getContent());
        $this->assertStringNotContainsString('This reward expired on', $staff->getContent());
    }

    public function test_action_center_row_aggregates_expiring_rewards(): void
    {
        [$business] = $this->availableReward(['expires_at' => now()->addDays(12)]);
        $owner = World::owner($business);
        $center = app(ActionCenter::class);

        $items = collect($center->items($business, $owner));
        $row = $items->firstWhere('key', 'rewards_expiring');
        $this->assertNotNull($row);
        $this->assertSame('1 customer reward runs out in the next 30 days.', $row['line']);
        $this->assertSame('See rewards', $row['action_label']);
        $this->assertContains('rewards_expiring', ActionCenter::sourceKeys());

        // A reward outside the window shows no row.
        [$farBusiness] = $this->availableReward(['expires_at' => now()->addDays(90)]);
        $farItems = collect($center->items($farBusiness, World::owner($farBusiness)));
        $this->assertNull($farItems->firstWhere('key', 'rewards_expiring'));
    }
}
