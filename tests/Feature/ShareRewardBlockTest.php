<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\Reward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * SIM-1 residual (PM review 2026-10-27, item B3): the share page
 * is the member's home page, and a member who already earned a
 * reward must see it there, with its state in plain words and
 * its path to use it. Before this build the claim URL existed
 * only inside SMS jobs, so a returning member whose friend had
 * visited saw no sign of the reward on the share page at all
 * (the state-visibility rule, violated on the core-loop page).
 *
 * The block shows the single most relevant reward (an available
 * reward beats a vesting one; within a state, the nearest
 * unlock first). It originally added a count when more existed;
 * SA-02 (Train B) deleted that count sentence and renders every
 * additional reward as its own row instead (pinned in
 * ShareAdditionalRewardsTest). With no vesting or available
 * reward, the block is absent and the prospective line stands
 * alone, exactly as before.
 */
class ShareRewardBlockTest extends TestCase
{
    use RefreshDatabase;

    private FakePhoneVerificationGateway $verify;

    private FakeSmsGateway $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verify = new FakePhoneVerificationGateway;
        $this->sms = new FakeSmsGateway;
        $this->app->instance(PhoneVerificationGateway::class, $this->verify);
        $this->app->instance(SmsGateway::class, $this->sms);
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

    private function enrollCustomer(Business $business, string $qrToken, string $phone = '+12125550111'): Membership
    {
        $this->carryFlowCookie($this->get(route('refer.landing', ['token' => $qrToken]))->assertOk());

        $this->carryFlowCookie($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat',
            'last_name' => 'Lee',
            'phone' => $phone,
            'consent' => '1',
        ])->assertRedirect(route('refer.verify')));

        $this->carryFlowCookie($this->post(route('refer.verify.check'), ['code' => '123456'])
            ->assertRedirect(route('refer.share')));

        return Membership::query()->where('business_id', $business->id)->latest('id')->firstOrFail();
    }

    /**
     * Mint a reward for the enrolled member the way the engine
     * does: a friend's redeemed referral plus a reward row in
     * the given state. Returns the reward and its claim token.
     *
     * @return array{0: Reward, 1: string}
     */
    private function earnReward(Membership $membership, array $overrides = [], string $friendPhone = '+12125550777'): array
    {
        $friend = World::customer($friendPhone, 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);

        return World::reward($membership, $referral, $overrides);
    }

    public function test_share_page_without_any_reward_has_no_reward_block(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->enrollCustomer($business, $qrToken);

        $this->get(route('refer.share'))
            ->assertOk()
            // The prospective line stands as-is.
            ->assertSee('When a friend visits '.$business->name.' with your link, you get $10 off')
            // No earned-reward block in any of its states.
            ->assertDontSee('Your reward is ready')
            ->assertDontSee('Your reward is on its way')
            ->assertDontSee('Held for review')
            ->assertDontSee('Use my reward')
            ->assertDontSee('See your reward')
            ->assertDontSee('more reward');
    }

    public function test_share_page_whitespace_only_referrer_reward_falls_back_to_the_friend_reward(): void
    {
        // Sibling of the RS-N2 prime block (Train B fix lane): a
        // whitespace-only referrer text is unset (the SA-12
        // state), so the prospective line must read with the
        // OD-2 friend-reward fallback, never an empty
        // "you get   " promise.
        $business = World::business(['referrer_reward_text' => '   ']);
        [, $qrToken] = World::qr($business);
        $this->enrollCustomer($business, $qrToken);

        $html = $this->get(route('refer.share'))->assertOk()->getContent();
        $this->assertStringContainsString('When a friend visits '.$business->name.' with your link, you get $10 off your first visit', $html);
        $this->assertDoesNotMatchRegularExpression('/you get\s+<\/p>/', $html);
    }

    public function test_share_page_shows_an_available_reward_with_its_claim_path(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $membership = $this->enrollCustomer($business, $qrToken);
        [, $claimToken] = $this->earnReward($membership, [
            'state' => Reward::STATE_AVAILABLE,
            'available_at' => now()->subDay(),
        ]);

        $this->get(route('refer.share'))
            ->assertOk()
            ->assertSee('Your reward is ready')
            ->assertSee('Your reward: $10 off.')
            ->assertSee('Use my reward')
            ->assertSee(route('refer.claim.page', ['token' => $claimToken]), false)
            // A single reward carries no count line.
            ->assertDontSee('more reward');
    }

    public function test_share_page_shows_a_vesting_reward_with_date_and_text_promise_together(): void
    {
        // SIM-3 parity: the vesting block shows BOTH the unlock
        // date and the "we will text you" line, never one alone.
        // The share page is the member's own verified session, so
        // the date disclosure matches the claim page's G-17 rule.
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $membership = $this->enrollCustomer($business, $qrToken);
        [$reward, $claimToken] = $this->earnReward($membership, [
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => now()->addDays(10),
        ]);
        $date = $business->localTime($reward->available_at)->format('l, F j, Y');

        $this->get(route('refer.share'))
            ->assertOk()
            ->assertSee('Your reward is on its way')
            ->assertSee('Your reward: $10 off.')
            ->assertSee('Your reward will be available on '.$date.'.')
            ->assertSee('We will text you when it is ready, unless you have opted out of texts. You can also check this page.')
            ->assertSee('See your reward')
            ->assertSee(route('refer.claim.page', ['token' => $claimToken]), false)
            ->assertDontSee('Use my reward');
    }

    public function test_a_vesting_reward_whose_date_has_passed_reads_as_ready_on_the_share_page(): void
    {
        // Vesting is applied on read, as on every other surface.
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $membership = $this->enrollCustomer($business, $qrToken);
        [$reward] = $this->earnReward($membership, [
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => now()->subHour(),
        ]);

        $this->get(route('refer.share'))
            ->assertOk()
            ->assertSee('Your reward is ready')
            ->assertDontSee('Your reward is on its way');

        $this->assertSame(Reward::STATE_AVAILABLE, $reward->fresh()->state);
    }

    public function test_an_available_reward_wins_over_a_vesting_one(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $membership = $this->enrollCustomer($business, $qrToken);
        $this->earnReward($membership, [
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => now()->addDay(),
            'reward_text_snapshot' => '$5 off',
        ], '+12125550777');
        [, $readyToken] = $this->earnReward($membership, [
            'state' => Reward::STATE_AVAILABLE,
            'available_at' => now()->subDay(),
            'reward_text_snapshot' => '$15 off',
        ], '+12125550666');

        $this->get(route('refer.share'))
            ->assertOk()
            ->assertSee('Your reward is ready')
            ->assertSee('Your reward: $15 off.')
            ->assertSee(route('refer.claim.page', ['token' => $readyToken]), false)
            // SA-02 (Train B): the vesting reward is no longer a
            // bare count; it renders as its own waiting row.
            ->assertSee('Your reward: $5 off.')
            ->assertSee('See your reward')
            ->assertDontSee('You have 1 more reward.');
    }

    public function test_among_vesting_rewards_the_nearest_unlock_shows_first(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $membership = $this->enrollCustomer($business, $qrToken);
        $this->earnReward($membership, [
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => now()->addDays(12),
            'reward_text_snapshot' => '$5 off',
        ], '+12125550777');
        [$near] = $this->earnReward($membership, [
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => now()->addDays(3),
            'reward_text_snapshot' => '$15 off',
        ], '+12125550666');
        $nearDate = $business->localTime($near->available_at)->format('l, F j, Y');

        $this->get(route('refer.share'))
            ->assertOk()
            ->assertSee('Your reward: $15 off.')
            ->assertSee('Your reward will be available on '.$nearDate.'.')
            // SA-02 (Train B): the later reward renders as its own
            // row with its own date, not as a count.
            ->assertSee('Your reward: $5 off.')
            ->assertDontSee('You have 1 more reward.');
    }

    public function test_every_additional_reward_renders_as_its_own_row_and_no_count_line(): void
    {
        // SA-02 (Train B, Amendment 39) supersedes the count-line
        // form of this test: REF-SHARE-11's "You have N more
        // rewards." is retired; both additional rewards render.
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $membership = $this->enrollCustomer($business, $qrToken);
        $this->earnReward($membership, ['state' => Reward::STATE_AVAILABLE], '+12125550777');
        $this->earnReward($membership, [
            'state' => Reward::STATE_PENDING_VESTING, 'available_at' => now()->addDays(5),
        ], '+12125550666');
        $this->earnReward($membership, [
            'state' => Reward::STATE_PENDING_VESTING, 'available_at' => now()->addDays(9),
        ], '+12125550555');

        $response = $this->get(route('refer.share'))
            ->assertOk()
            ->assertSee('Your reward is ready')
            ->assertSee('More rewards')
            ->assertDontSee('You have 2 more rewards.');

        $this->assertSame(3, substr_count($response->getContent(), 'Your reward: $10 off.'));
    }

    public function test_a_held_reward_shows_the_held_state_on_the_share_page(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $membership = $this->enrollCustomer($business, $qrToken);
        $this->earnReward($membership, [
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => now()->addDays(10),
            'hold' => true,
            'hold_rules' => ['velocity_daily'],
            'held_at' => now(),
        ]);

        $this->get(route('refer.share'))
            ->assertOk()
            ->assertSee('Held for review')
            ->assertSee('Your reward: $10 off.')
            ->assertSee('Your reward is being confirmed by '.$business->name.'.')
            ->assertSee('Nothing for you to do. We will text you when it is ready, unless you have opted out of texts. You can also check this page.')
            // A held reward makes no vesting-date promise.
            ->assertDontSee('Your reward will be available on');
    }

    public function test_claimed_denied_and_expired_rewards_show_no_block(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $membership = $this->enrollCustomer($business, $qrToken);
        $this->earnReward($membership, [
            'state' => Reward::STATE_CLAIMED, 'claimed_at' => now(),
        ], '+12125550777');
        $this->earnReward($membership, [
            'state' => Reward::STATE_DENIED,
        ], '+12125550666');
        $this->earnReward($membership, [
            'state' => Reward::STATE_EXPIRED,
        ], '+12125550555');

        $this->get(route('refer.share'))
            ->assertOk()
            ->assertDontSee('Your reward is ready')
            ->assertDontSee('Your reward is on its way')
            ->assertDontSee('Use my reward');
    }

    public function test_another_members_reward_never_shows_on_this_share_page(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->enrollCustomer($business, $qrToken);

        $other = World::customer('+12125550999', 'Oth', 'Er');
        [$otherMembership] = World::membership($business, $other);
        $this->earnReward($otherMembership, ['state' => Reward::STATE_AVAILABLE]);

        $this->get(route('refer.share'))
            ->assertOk()
            ->assertDontSee('Your reward is ready')
            ->assertDontSee('Use my reward');
    }

    public function test_a_reward_past_the_closure_window_shows_no_block(): void
    {
        // The closure wind-down end (spec Section 5.5) applies
        // on this read too, as it does on the claim page.
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $membership = $this->enrollCustomer($business, $qrToken);
        [$reward] = $this->earnReward($membership, ['state' => Reward::STATE_AVAILABLE]);

        $business->forceFill(['status' => 'closed', 'closed_at' => now()->subDays(31)])->save();

        $this->get(route('refer.share'))
            ->assertOk()
            ->assertDontSee('Your reward is ready')
            ->assertDontSee('Use my reward');

        $this->assertSame(Reward::STATE_EXPIRED, $reward->fresh()->state);
    }
}
