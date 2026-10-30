<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Jobs\SendRewardReadySms;
use App\Models\Business;
use App\Models\Reward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * FH-OB1 (outbound truth sweep OB-01, HIGH): the reward-ready SMS
 * asserts "ready to be claimed", so at SEND time — in the job and
 * in the owner "Text again" resend alike — the reward must
 * actually be claimable: state available, not held, closure
 * expiry applied. Denied / held / closure-expired / already
 * claimed rewards send NOTHING.
 */
class RewardReadySmsGuardTest extends TestCase
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

    /** @return array{0: Business, 1: Reward} */
    private function rewardWith(array $overrides = []): array
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, ['state' => 'redeemed', 'redeemed_at' => now()->subDays(20)]);
        [$reward] = World::reward($membership, $referral, array_merge([
            'state' => Reward::STATE_AVAILABLE,
            'available_at' => now()->subDay(),
            'notification_state' => 'queued',
        ], $overrides));

        return [$business, $reward];
    }

    public function test_available_unheld_reward_still_sends(): void
    {
        [, $reward] = $this->rewardWith();
        (new SendRewardReadySms($reward->id))->handle($this->sms);
        $this->assertCount(1, $this->sms->sent);
        $this->assertSame('sent', $reward->fresh()->notification_state);
    }

    public function test_denied_reward_sends_nothing(): void
    {
        [, $reward] = $this->rewardWith(['state' => Reward::STATE_DENIED]);
        (new SendRewardReadySms($reward->id))->handle($this->sms);
        $this->assertCount(0, $this->sms->sent);
        $this->assertSame('suppressed', $reward->fresh()->notification_state);
    }

    public function test_held_reward_sends_nothing_including_cooling_and_review_holds(): void
    {
        foreach ([['device_signal_reuse'], ['number_link_cooling']] as $rules) {
            [, $reward] = $this->rewardWith([
                'hold' => true, 'hold_rules' => $rules, 'held_at' => now(),
            ]);
            (new SendRewardReadySms($reward->id))->handle($this->sms);
            // Nothing sends, and the notification stays QUEUED:
            // a later release (which dispatches only queued
            // rewards) must still be able to text the referrer
            // once the reward is genuinely claimable.
            $this->assertSame('queued', $reward->fresh()->notification_state);
        }
        $this->assertCount(0, $this->sms->sent);
    }

    public function test_still_vesting_reward_sends_nothing(): void
    {
        [, $reward] = $this->rewardWith([
            'state' => Reward::STATE_PENDING_VESTING, 'available_at' => now()->addDays(3),
        ]);
        (new SendRewardReadySms($reward->id))->handle($this->sms);
        $this->assertCount(0, $this->sms->sent);
    }

    public function test_claimed_reward_sends_nothing(): void
    {
        [, $reward] = $this->rewardWith(['state' => Reward::STATE_CLAIMED, 'claimed_at' => now()]);
        (new SendRewardReadySms($reward->id))->handle($this->sms);
        $this->assertCount(0, $this->sms->sent);
        $this->assertSame('suppressed', $reward->fresh()->notification_state);
    }

    public function test_closure_expired_reward_sends_nothing_and_expires(): void
    {
        [$business, $reward] = $this->rewardWith();
        $business->forceFill([
            'status' => 'closed', 'closed_at' => now()->subDays(31),
        ])->save();

        (new SendRewardReadySms($reward->id))->handle($this->sms);
        $this->assertCount(0, $this->sms->sent);
        $this->assertSame(Reward::STATE_EXPIRED, $reward->fresh()->state);
        $this->assertSame('suppressed', $reward->fresh()->notification_state);
    }

    public function test_resend_refuses_every_non_claimable_state(): void
    {
        $cases = [
            'denied' => ['state' => Reward::STATE_DENIED],
            'held' => ['hold' => true, 'hold_rules' => ['velocity_daily'], 'held_at' => now()],
            'claimed' => ['state' => Reward::STATE_CLAIMED, 'claimed_at' => now()],
            'vesting' => ['state' => Reward::STATE_PENDING_VESTING, 'available_at' => now()->addDays(2)],
        ];

        foreach ($cases as $label => $overrides) {
            [$business, $reward] = $this->rewardWith(array_merge($overrides, ['notification_state' => 'failed']));
            $owner = World::owner($business);
            $this->actingAs($owner)
                ->post(route('rewards.resend', ['reward' => $reward->id]))
                ->assertSessionHasErrors('reward');
            $this->assertSame('failed', $reward->fresh()->notification_state, "{$label}: resend must not re-queue");
        }
        $this->assertCount(0, $this->sms->sent);
    }

    public function test_resend_of_genuinely_claimable_failed_reward_still_works(): void
    {
        [$business, $reward] = $this->rewardWith(['notification_state' => 'failed']);
        $owner = World::owner($business);
        $this->actingAs($owner)
            ->post(route('rewards.resend', ['reward' => $reward->id]))
            ->assertSessionHas('status', 'Text sent again.');
        // Sync queue: the job ran and, the reward being claimable,
        // the text actually went out.
        $this->assertCount(1, $this->sms->sent);
        $this->assertSame('sent', $reward->fresh()->notification_state);
    }

    public function test_resend_refuses_closure_expired_reward(): void
    {
        [$business, $reward] = $this->rewardWith(['notification_state' => 'failed']);
        $business->forceFill(['status' => 'closed', 'closed_at' => now()->subDays(31)])->save();
        $owner = World::owner($business);
        // A closed business's resend is refused before any send —
        // by the closed-business middleware and, where it reaches
        // the controller, by the FH-OB1 guard itself. Either way:
        // errors back, nothing sent.
        $this->actingAs($owner)
            ->post(route('rewards.resend', ['reward' => $reward->id]))
            ->assertSessionHasErrors();
        $this->assertCount(0, $this->sms->sent);
    }
}
