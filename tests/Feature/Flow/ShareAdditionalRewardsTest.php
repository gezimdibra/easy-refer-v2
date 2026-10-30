<?php

namespace Tests\Feature\Flow;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\Reward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * SA-02 (Train B, Amendment 39): every live reward a member holds
 * is reachable from the share page, in its own true state. Before
 * this build the page showed the top reward and a bare count
 * ("You have N more rewards.") that presented held and waiting
 * rewards as an undifferentiated "have" and gave none of them a
 * path. The count sentence is DELETED (REF-SHARE-11 retired);
 * below the unchanged top block, a "More rewards" section holds
 * one compact row per additional reward (design pass Variant B):
 * the state's pill, the snapshot, the state's one truth line,
 * and the state's own action linking that reward's own claim
 * URL, classified held first, then ready, then waiting, exactly
 * as the top block and claim page classify.
 */
class ShareAdditionalRewardsTest extends TestCase
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

    private function carryFlowCookie(TestResponse $response): void
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

    /** @return array{0: Reward, 1: string} */
    private function earnReward(Membership $membership, array $overrides = [], string $friendPhone = '+12125550777'): array
    {
        $friend = World::customer($friendPhone, 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);

        return World::reward($membership, $referral, $overrides);
    }

    private function shareHtml(): string
    {
        return $this->get(route('refer.share'))->assertOk()->getContent();
    }

    public function test_two_ready_rewards_both_render_with_their_own_claim_urls_and_actions(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $membership = $this->enrollCustomer($business, $qrToken);
        [, $topToken] = $this->earnReward($membership, [
            'state' => Reward::STATE_AVAILABLE, 'reward_text_snapshot' => '$10 off',
        ], '+12125550777');
        [, $secondToken] = $this->earnReward($membership, [
            'state' => Reward::STATE_AVAILABLE, 'reward_text_snapshot' => '$20 off',
        ], '+12125550666');

        $html = $this->shareHtml();

        $this->assertStringContainsString('More rewards', $html);
        $this->assertStringContainsString('Your reward: $10 off.', $html);
        $this->assertStringContainsString('Your reward: $20 off.', $html);
        $this->assertStringContainsString(route('refer.claim.page', ['token' => $topToken]), $html);
        $this->assertStringContainsString(route('refer.claim.page', ['token' => $secondToken]), $html);
        $this->assertSame(2, substr_count($html, 'Use my reward'));
        // The count sentence is deleted, in both number forms.
        $this->assertDoesNotMatchRegularExpression('/You have \d+ more rewards?\./', $html);
    }

    public function test_ready_plus_waiting_the_waiting_row_carries_its_own_date_and_no_ready_framing(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $membership = $this->enrollCustomer($business, $qrToken);
        $this->earnReward($membership, ['state' => Reward::STATE_AVAILABLE], '+12125550777');
        [$waiting] = $this->earnReward($membership, [
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => now()->addDays(9),
            'reward_text_snapshot' => '$5 off',
        ], '+12125550666');
        $date = $business->localTime($waiting->available_at)->format('l, F j, Y');

        $html = $this->shareHtml();

        $this->assertSame(1, substr_count($html, 'Your reward is ready'));
        $this->assertSame(1, substr_count($html, 'Use my reward'));
        $this->assertStringContainsString('Your reward: $5 off.', $html);
        $this->assertStringContainsString('Your reward will be available on '.$date.'.', $html);
        $this->assertStringContainsString('See your reward', $html);
    }

    public function test_ready_plus_held_the_held_row_has_no_date_and_no_use_action(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $membership = $this->enrollCustomer($business, $qrToken);
        $this->earnReward($membership, ['state' => Reward::STATE_AVAILABLE], '+12125550777');
        [, $heldToken] = $this->earnReward($membership, [
            'state' => Reward::STATE_AVAILABLE,
            'hold' => true,
            'hold_rules' => ['risk_score'],
            'held_at' => now(),
            'reward_text_snapshot' => '$7 off',
        ], '+12125550666');

        $html = $this->shareHtml();

        $this->assertStringContainsString('Your reward: $7 off.', $html);
        $this->assertStringContainsString('In review', $html);
        $this->assertStringContainsString('Your reward is being confirmed by '.$business->name.'.', $html);
        $this->assertSame(1, substr_count($html, 'Use my reward'), 'only the ready top block offers Use my reward');
        $this->assertStringContainsString(route('refer.claim.page', ['token' => $heldToken]), $html);

        // The held row's own claim page answers in the held state.
        $this->get(route('refer.claim.page', ['token' => $heldToken]))
            ->assertOk()
            ->assertSee('Held for review');
    }

    public function test_two_waiting_rewards_each_render_beside_their_own_date(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $membership = $this->enrollCustomer($business, $qrToken);
        [$near] = $this->earnReward($membership, [
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => now()->addDays(3),
            'reward_text_snapshot' => '$15 off',
        ], '+12125550777');
        [$far] = $this->earnReward($membership, [
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => now()->addDays(12),
            'reward_text_snapshot' => '$5 off',
        ], '+12125550666');
        $nearDate = $business->localTime($near->available_at)->format('l, F j, Y');
        $farDate = $business->localTime($far->available_at)->format('l, F j, Y');

        $html = $this->shareHtml();

        // Each snapshot is followed, inside its own block or row,
        // by its own date (order-independent per-reward check).
        foreach ([['$15 off', $nearDate], ['$5 off', $farDate]] as [$snapshot, $date]) {
            $at = strpos($html, 'Your reward: '.$snapshot.'.');
            $this->assertNotFalse($at);
            $window = substr($html, $at, 1200);
            $this->assertStringContainsString('Your reward will be available on '.$date.'.', $window);
        }
    }

    public function test_a_vesting_reward_that_flips_on_this_read_renders_as_a_ready_row(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $membership = $this->enrollCustomer($business, $qrToken);
        $this->earnReward($membership, ['state' => Reward::STATE_AVAILABLE], '+12125550777');
        $this->earnReward($membership, [
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => now()->subHour(),
            'reward_text_snapshot' => '$5 off',
        ], '+12125550666');

        $html = $this->shareHtml();

        $this->assertStringContainsString('Your reward: $5 off.', $html);
        $this->assertSame(2, substr_count($html, 'Use my reward'));
    }

    public function test_terminal_rewards_are_neither_listed_nor_counted(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $membership = $this->enrollCustomer($business, $qrToken);
        $this->earnReward($membership, ['state' => Reward::STATE_AVAILABLE], '+12125550777');
        $this->earnReward($membership, [
            'state' => Reward::STATE_CLAIMED, 'claimed_at' => now(), 'reward_text_snapshot' => 'CLAIMED-SNAP',
        ], '+12125550666');
        $this->earnReward($membership, [
            'state' => Reward::STATE_DENIED, 'reward_text_snapshot' => 'DENIED-SNAP',
        ], '+12125550555');
        $this->earnReward($membership, [
            'state' => Reward::STATE_EXPIRED, 'reward_text_snapshot' => 'EXPIRED-SNAP',
        ], '+12125550444');

        $html = $this->shareHtml();

        $this->assertStringNotContainsString('CLAIMED-SNAP', $html);
        $this->assertStringNotContainsString('DENIED-SNAP', $html);
        $this->assertStringNotContainsString('EXPIRED-SNAP', $html);
        $this->assertStringNotContainsString('More rewards', $html);
        $this->assertDoesNotMatchRegularExpression('/You have \d+ more rewards?\./', $html);
    }

    public function test_zero_and_one_live_reward_render_no_additional_section(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $membership = $this->enrollCustomer($business, $qrToken);

        $this->assertStringNotContainsString('More rewards', $this->shareHtml());

        $this->earnReward($membership, ['state' => Reward::STATE_AVAILABLE]);

        $html = $this->shareHtml();
        $this->assertStringNotContainsString('More rewards', $html);
        $this->assertStringContainsString('Your reward is ready', $html);
    }

    public function test_another_members_rewards_never_appear_as_rows(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $membership = $this->enrollCustomer($business, $qrToken);
        $this->earnReward($membership, ['state' => Reward::STATE_AVAILABLE], '+12125550777');

        $other = World::customer('+12125550999', 'Oth', 'Er');
        [$otherMembership] = World::membership($business, $other);
        [, $otherToken] = $this->earnReward($otherMembership, [
            'state' => Reward::STATE_AVAILABLE, 'reward_text_snapshot' => 'OTHER-SNAP',
        ], '+12125550333');

        $html = $this->shareHtml();
        $this->assertStringNotContainsString('OTHER-SNAP', $html);
        $this->assertStringNotContainsString(route('refer.claim.page', ['token' => $otherToken]), $html);
    }

    public function test_a_spent_rewards_claim_link_shows_the_claimed_receipt_and_leaves_the_share_page(): void
    {
        // Gaming acceptance (pass 1.5): the second reward's link
        // is forwarded and spent; reopening shows the claimed
        // receipt, and the share page no longer lists it.
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $membership = $this->enrollCustomer($business, $qrToken);
        $this->earnReward($membership, ['state' => Reward::STATE_AVAILABLE], '+12125550777');
        [$second, $secondToken] = $this->earnReward($membership, [
            'state' => Reward::STATE_AVAILABLE, 'reward_text_snapshot' => '$20 off',
        ], '+12125550666');

        $second->forceFill(['state' => Reward::STATE_CLAIMED, 'claimed_at' => now()])->save();

        $this->get(route('refer.claim.page', ['token' => $secondToken]))
            ->assertOk()
            ->assertSee('Reward claimed');

        $html = $this->shareHtml();
        $this->assertStringNotContainsString('Your reward: $20 off.', $html);
        $this->assertStringNotContainsString('More rewards', $html);
    }

    public function test_mixed_three_additional_rewards_render_independently_classified_rows(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $membership = $this->enrollCustomer($business, $qrToken);
        $this->earnReward($membership, ['state' => Reward::STATE_AVAILABLE, 'reward_text_snapshot' => 'TOP-SNAP'], '+12125550777');
        $this->earnReward($membership, [
            'state' => Reward::STATE_AVAILABLE, 'hold' => true, 'hold_rules' => ['risk_score'], 'held_at' => now(),
            'reward_text_snapshot' => 'HELD-SNAP',
        ], '+12125550666');
        [$waiting] = $this->earnReward($membership, [
            'state' => Reward::STATE_PENDING_VESTING, 'available_at' => now()->addDays(6),
            'reward_text_snapshot' => 'WAIT-SNAP',
        ], '+12125550555');
        $this->earnReward($membership, [
            'state' => Reward::STATE_AVAILABLE, 'reward_text_snapshot' => 'READY-SNAP',
        ], '+12125550444');
        $waitDate = $business->localTime($waiting->available_at)->format('l, F j, Y');

        $html = $this->shareHtml();

        foreach (['TOP-SNAP', 'HELD-SNAP', 'WAIT-SNAP', 'READY-SNAP'] as $snap) {
            $this->assertStringContainsString('Your reward: '.$snap.'.', $html);
        }
        $this->assertStringContainsString('Your reward will be available on '.$waitDate.'.', $html);
        // Ready rows: the top block plus the ready row. The held
        // and waiting rows link with See your reward only.
        $this->assertSame(2, substr_count($html, 'Use my reward'));
        $this->assertSame(2, substr_count($html, 'See your reward'));
    }
}
