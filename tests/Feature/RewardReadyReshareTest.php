<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Jobs\SendRewardReadySms;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\Reward;
use App\Models\SmsMessage;
use App\Models\SmsOptOut;
use App\Services\Referral\MessageCopy;
use App\Support\SmsSegments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * ID-07 (Amendment 51): the reward-ready reshare. The shipped
 * reward-ready SMS gains ONE gated appendage carrying the
 * referrer's own share link (the came-as-a-friend cohort's form
 * opens with [F2R-03]), and the claim page's ready state gains
 * the matching reshare block. Rules under pin (design pass
 * idea-07-design-pass.md): RR-GATE (flowIsActive + live token,
 * evaluated at SEND time in the job), RR-CAP (one appendage
 * send per (business, customer) per rolling 7 days, counted on
 * sent RR-M1 rows only), RR-SEG (composed <= base + 1 segment),
 * RR-M1 (template_key 'reward_ready_reshare' is the cap ledger
 * + measurement stamp; consent_basis stays 'transactional').
 * The base text is byte-untouched and never capped, delayed,
 * or suppressed by any appendage rule.
 *
 * TS3-12 / removed_at (coordinator note 2026-10-29, pass
 * Sections 6 V11 + 11): the appendage is FALSE for a removed
 * member (their share token is revoked; they cannot share),
 * so RR-GATE conjunct 2 suppresses the APPENDAGE. The BASE
 * send is NOT suppressed: the reward-ready sentence stays
 * true for a removed member because the claim path has no
 * removal gate either (earned rewards survive removal: the
 * shipped behaviour the pass's V11 walk treats as intent).
 * Whether the base send itself should gain a removed_at
 * guard is the truth rotation's named question (pass
 * Section 11), NOT absorbed by this build.
 */
class RewardReadyReshareTest extends TestCase
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

    /**
     * @return array{0: Business, 1: Membership, 2: Reward, 3: string, 4: string}
     *               business, membership, reward, plain share token, plain claim token
     */
    private function fixture(array $businessOverrides = [], array $rewardOverrides = [], string $phone = '+12125550999'): array
    {
        $business = World::business($businessOverrides);
        $referrer = World::customer($phone, 'Reg', 'Ular');
        [$membership, $shareToken] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(20)]);
        $referral->forceFill(['created_at' => now()->subDays(21)])->save();
        [$reward, $claimToken] = World::reward($membership, $referral, array_merge([
            'state' => Reward::STATE_AVAILABLE,
            'available_at' => now()->subDay(),
            'notification_state' => 'queued',
        ], $rewardOverrides));

        return [$business, $membership, $reward, $shareToken, $claimToken];
    }

    private function baseBody(Business $business, Reward $reward, string $claimToken): string
    {
        return MessageCopy::rewardReady($business, $reward->reward_text_snapshot, route('refer.claim.page', ['token' => $claimToken]));
    }

    private function plainAppendage(string $shareToken): string
    {
        return ' Share your link with a friend: '.route('refer.friend', ['token' => $shareToken]);
    }

    private function pauseBusiness(Business $business): void
    {
        $business->forceFill(['billing_status' => 'past_due'])->save();
        Carbon::setTestNow(now()->subDays(30));
        AuditLog::write('billing.past_due', null, [], $business->fresh(), null);
        Carbon::setTestNow(null);
    }

    // ------------------------------------------------------------ 10.2.1 V1

    public function test_clean_vesting_send_carries_plain_appendage_byte_exact_and_stamps_reshare(): void
    {
        [$business, $membership, $reward, $shareToken, $claimToken] = $this->fixture();

        (new SendRewardReadySms($reward->id))->handle($this->sms);

        $this->assertCount(1, $this->sms->sent);
        $expected = $this->baseBody($business, $reward, $claimToken).$this->plainAppendage($shareToken);
        $this->assertSame($expected, $this->sms->sent[0]['body']);

        $row = SmsMessage::query()->sole();
        $this->assertSame('reward_ready_reshare', $row->template_key, 'RR-M1 stamp');
        $this->assertSame('reward_ready', $row->purpose);
        $this->assertSame('transactional', $row->consent_basis);
        $this->assertSame('sent', $row->delivery_state);
        // Stored evidence redacts BOTH tokens (F-09 hygiene).
        $this->assertStringNotContainsString($claimToken, $row->rendered_body);
        $this->assertStringNotContainsString($shareToken, $row->rendered_body);
        $this->assertStringContainsString('Share your link with a friend', $row->rendered_body);
        // Absence pins (10.2.11): no earn verb, no cohort clause
        // for a non-cohort referrer, no amount beyond the base's.
        $this->assertStringNotContainsString('earn', $this->sms->sent[0]['body']);
        $this->assertStringNotContainsString('You came in through a friend', $this->sms->sent[0]['body']);
    }

    // ------------------------------------------------------------ 10.2.2 cohort [F2R-03]

    public function test_came_as_friend_cohort_opens_the_appendage_with_f2r03(): void
    {
        [$business, $membership, $reward, $shareToken, $claimToken] = $this->fixture();
        // Provenance: Reg arrived at THIS business as a redeemed
        // friend of another referrer (ID-08 Section 7.2 join).
        $other = World::customer('+12125550333', 'Oth', 'Er');
        [$otherMembership] = World::membership($business, $other);
        World::referral($otherMembership, $membership->customer, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(40)]);

        (new SendRewardReadySms($reward->id))->handle($this->sms);

        $expected = $this->baseBody($business, $reward, $claimToken)
            .' You came in through a friend yourself. Share your link: '.route('refer.friend', ['token' => $shareToken]);
        $this->assertSame($expected, $this->sms->sent[0]['body']);
        $this->assertLessThan(
            strpos($this->sms->sent[0]['body'], 'Share your link'),
            strpos($this->sms->sent[0]['body'], 'You came in through a friend yourself.'),
            '[F2R-03] precedes the share action'
        );
    }

    // ------------------------------------------------------------ 10.2.3 flow gate states

    public function test_past_due_in_grace_still_carries_the_appendage(): void
    {
        [$business, , $reward, $shareToken] = $this->fixture(['billing_status' => 'past_due']);

        // RE-PINNED at the flow-state merge (2026-10-30): an
        // in-grace episode must be DATED the way production
        // dates it — the webhook's billing.past_due audit row,
        // written here one day ago, so the grace end is six
        // days out and the flow is genuinely active. The old
        // fixture relied on the retired undated-never-pauses
        // rule (no audit row, no failed record); under the
        // fail-closed derivation that state is paused, and the
        // appendage correctly stays off (pinned by the
        // past-grace test below via pauseBusiness()).
        Carbon::setTestNow(now()->subDay());
        AuditLog::write('billing.past_due', null, [], $business->fresh(), null);
        Carbon::setTestNow(null);

        $this->assertTrue($business->fresh()->flowIsActive(), 'dated episode inside grace: flow is active');

        (new SendRewardReadySms($reward->id))->handle($this->sms);

        $this->assertCount(1, $this->sms->sent);
        $this->assertStringContainsString($this->plainAppendage($shareToken), $this->sms->sent[0]['body']);
    }

    public function test_past_due_past_grace_sends_the_base_text_only_byte_identical(): void
    {
        [$business, , $reward, , $claimToken] = $this->fixture();
        $this->pauseBusiness($business);

        (new SendRewardReadySms($reward->id))->handle($this->sms);

        $this->assertCount(1, $this->sms->sent);
        $this->assertSame($this->baseBody($business->fresh(), $reward, $claimToken), $this->sms->sent[0]['body']);
        $this->assertSame('reward_ready', SmsMessage::query()->sole()->template_key);
    }

    public function test_closed_inside_the_wind_down_sends_the_base_text_only(): void
    {
        [$business, , $reward, , $claimToken] = $this->fixture();
        $business->forceFill(['status' => 'closed', 'closed_at' => now()->subDays(5)])->save();

        (new SendRewardReadySms($reward->id))->handle($this->sms);

        $this->assertCount(1, $this->sms->sent);
        $this->assertSame($this->baseBody($business->fresh(), $reward, $claimToken), $this->sms->sent[0]['body']);
    }

    public function test_textless_business_sends_the_base_text_only(): void
    {
        [$business, , $reward, , $claimToken] = $this->fixture();
        $business->forceFill(['friend_reward_text' => null])->save();

        (new SendRewardReadySms($reward->id))->handle($this->sms);

        $this->assertCount(1, $this->sms->sent);
        $this->assertSame($this->baseBody($business->fresh(), $reward, $claimToken), $this->sms->sent[0]['body']);
    }

    // ------------------------------------------------------------ TS3-12 removed_at (10.2.6 V11)

    public function test_removed_member_gets_the_base_text_but_no_appendage_ts3_12(): void
    {
        [$business, $membership, $reward, $shareToken, $claimToken] = $this->fixture();
        // The owner-removal shape (CustomersController): the
        // membership is stamped removed and its token revoked.
        $membership->forceFill(['removed_at' => now(), 'share_token_state' => 'revoked'])->save();

        (new SendRewardReadySms($reward->id))->handle($this->sms);

        // The earned reward's text still sends (claim path has
        // no removal gate; pass V11): with NO appendage and the
        // revoked share link nowhere in the body.
        $this->assertCount(1, $this->sms->sent);
        $this->assertSame($this->baseBody($business, $reward, $claimToken), $this->sms->sent[0]['body']);
        $this->assertStringNotContainsString(route('refer.friend', ['token' => $shareToken]), $this->sms->sent[0]['body']);
        $this->assertSame('reward_ready', SmsMessage::query()->sole()->template_key);
    }

    public function test_revoked_token_without_removal_also_gets_no_appendage(): void
    {
        [$business, $membership, $reward, , $claimToken] = $this->fixture();
        $membership->forceFill(['share_token_state' => 'revoked'])->save();

        (new SendRewardReadySms($reward->id))->handle($this->sms);

        $this->assertCount(1, $this->sms->sent);
        $this->assertSame($this->baseBody($business, $reward, $claimToken), $this->sms->sent[0]['body']);
    }

    // ------------------------------------------------------------ 10.2.4 hold / vesting timing

    public function test_held_reward_sends_nothing_then_release_after_vesting_sends_once_with_appendage(): void
    {
        [, , $reward, $shareToken] = $this->fixture([], [
            'hold' => true, 'hold_rules' => ['device_signal_reuse'], 'held_at' => now(),
        ]);

        (new SendRewardReadySms($reward->id))->handle($this->sms);
        $this->assertCount(0, $this->sms->sent);
        $this->assertSame('queued', $reward->fresh()->notification_state);

        $reward->forceFill(['hold' => false, 'hold_rules' => null, 'held_at' => null])->save();
        (new SendRewardReadySms($reward->id))->handle($this->sms);

        $this->assertCount(1, $this->sms->sent);
        $this->assertStringContainsString($this->plainAppendage($shareToken), $this->sms->sent[0]['body']);
    }

    public function test_appendage_is_evaluated_at_send_time_a_pause_between_vesting_and_release_drops_it(): void
    {
        [$business, , $reward, , $claimToken] = $this->fixture([], [
            'hold' => true, 'hold_rules' => ['device_signal_reuse'], 'held_at' => now(),
        ]);
        // The business pauses while the reward sits held; the
        // release happens into the paused state.
        $this->pauseBusiness($business);
        $reward->forceFill(['hold' => false, 'hold_rules' => null, 'held_at' => null])->save();

        (new SendRewardReadySms($reward->id))->handle($this->sms);

        $this->assertCount(1, $this->sms->sent);
        $this->assertSame($this->baseBody($business->fresh(), $reward, $claimToken), $this->sms->sent[0]['body']);
    }

    public function test_release_before_vesting_sends_nothing_until_due_then_exactly_once(): void
    {
        [, , $reward, $shareToken] = $this->fixture([], [
            'state' => Reward::STATE_PENDING_VESTING, 'available_at' => now()->addDays(2),
        ]);

        (new SendRewardReadySms($reward->id))->handle($this->sms);
        $this->assertCount(0, $this->sms->sent);

        $reward->forceFill(['available_at' => now()->subMinute()])->save();
        (new SendRewardReadySms($reward->id))->handle($this->sms);
        (new SendRewardReadySms($reward->id))->handle($this->sms);

        $this->assertCount(1, $this->sms->sent);
        $this->assertStringContainsString($this->plainAppendage($shareToken), $this->sms->sent[0]['body']);
    }

    // ------------------------------------------------------------ 10.2.5 never resurrects

    public function test_appendage_code_never_resurrects_a_suppressed_reward(): void
    {
        [, , $denied] = $this->fixture([], ['state' => Reward::STATE_DENIED]);
        (new SendRewardReadySms($denied->id))->handle($this->sms);

        [, , $claimed] = $this->fixture([], ['state' => Reward::STATE_CLAIMED, 'claimed_at' => now()], '+12125550101');
        (new SendRewardReadySms($claimed->id))->handle($this->sms);

        [$b3, , $optedOut] = $this->fixture([], [], '+12125550202');
        SmsOptOut::create(['phone_e164' => '+12125550202', 'opted_out_at' => now(), 'source' => 'carrier_stop']);
        (new SendRewardReadySms($optedOut->id))->handle($this->sms);

        $this->assertCount(0, $this->sms->sent);
        $this->assertSame(0, SmsMessage::query()->count());
    }

    // ------------------------------------------------------------ 10.2.7 RR-CAP

    public function test_rr_cap_first_send_in_a_cluster_wins_and_the_cap_reopens_after_seven_days(): void
    {
        [$business, $membership, $first, $shareToken] = $this->fixture();
        $rewards = [$first];
        foreach (['+12125550801', '+12125550802'] as $friendPhone) {
            $friend = World::customer($friendPhone);
            [$referral] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(3)]);
            [$extra] = World::reward($membership, $referral, [
                'state' => Reward::STATE_AVAILABLE, 'available_at' => now()->subDay(), 'notification_state' => 'queued',
            ]);
            $rewards[] = $extra;
        }

        foreach ($rewards as $reward) {
            (new SendRewardReadySms($reward->id))->handle($this->sms);
        }

        // Three base texts, exactly ONE appendage, on the first.
        $this->assertCount(3, $this->sms->sent);
        $this->assertStringContainsString('Share your link', $this->sms->sent[0]['body']);
        $this->assertStringNotContainsString('Share your link', $this->sms->sent[1]['body']);
        $this->assertStringNotContainsString('Share your link', $this->sms->sent[2]['body']);
        $this->assertSame(
            ['reward_ready_reshare', 'reward_ready', 'reward_ready'],
            SmsMessage::query()->orderBy('id')->pluck('template_key')->all()
        );

        // Eight days later the window has rolled: a fourth
        // reward carries the appendage again.
        SmsMessage::query()->where('template_key', 'reward_ready_reshare')
            ->update(['created_at' => now()->subDays(8)]);
        $friend = World::customer('+12125550803');
        [$referral] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()]);
        [$fourth] = World::reward($membership, $referral, [
            'state' => Reward::STATE_AVAILABLE, 'available_at' => now()->subDay(), 'notification_state' => 'queued',
        ]);
        (new SendRewardReadySms($fourth->id))->handle($this->sms);
        $this->assertCount(4, $this->sms->sent);
        $this->assertStringContainsString('Share your link', $this->sms->sent[3]['body']);
    }

    public function test_rr_cap_is_scoped_per_business_not_per_phone(): void
    {
        [, , $rewardA, $shareTokenA] = $this->fixture();
        (new SendRewardReadySms($rewardA->id))->handle($this->sms);

        // The same customer, a second business, inside the same
        // week: its own appendage (each business's rewards are
        // a separate fact about them).
        [, , $rewardB, $shareTokenB] = $this->fixture([], [], '+12125550999');
        (new SendRewardReadySms($rewardB->id))->handle($this->sms);

        $this->assertCount(2, $this->sms->sent);
        $this->assertStringContainsString($this->plainAppendage($shareTokenA), $this->sms->sent[0]['body']);
        $this->assertStringContainsString($this->plainAppendage($shareTokenB), $this->sms->sent[1]['body']);
    }

    // ------------------------------------------------------------ 10.2.8 resend + 10.3 owner gaming

    public function test_resend_of_a_failed_appendage_text_recomposes_it_because_failure_never_consumed_the_cap(): void
    {
        [$business, , $reward, $shareToken] = $this->fixture();
        $this->sms->failFor = ['+12125550999'];
        (new SendRewardReadySms($reward->id))->handle($this->sms);
        $this->assertCount(0, $this->sms->sent);
        $this->assertSame('failed', $reward->fresh()->notification_state);
        $this->assertSame('reward_ready_reshare', SmsMessage::query()->sole()->template_key);
        $this->assertSame('failed', SmsMessage::query()->sole()->delivery_state);

        $this->sms->failFor = [];
        $owner = World::owner($business);
        $this->actingAs($owner)
            ->post(route('rewards.resend', ['reward' => $reward->id]))
            ->assertSessionHas('status', 'Text sent again.');

        $this->assertCount(1, $this->sms->sent);
        $this->assertStringContainsString($this->plainAppendage($shareToken), $this->sms->sent[0]['body']);
    }

    public function test_owner_resend_after_a_delivered_appendage_sends_base_only(): void
    {
        [$business, $membership, $first, , $claimToken] = $this->fixture();
        (new SendRewardReadySms($first->id))->handle($this->sms);
        $this->assertCount(1, $this->sms->sent);

        $friend = World::customer('+12125550811');
        [$referral] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()]);
        [$second] = World::reward($membership, $referral, [
            'state' => Reward::STATE_AVAILABLE, 'available_at' => now()->subDay(), 'notification_state' => 'failed',
        ]);

        $owner = World::owner($business);
        $this->actingAs($owner)
            ->post(route('rewards.resend', ['reward' => $second->id]))
            ->assertSessionHas('status', 'Text sent again.');

        $this->assertCount(2, $this->sms->sent);
        $this->assertSame($this->baseBody($business, $second, $second->claim_token_encrypted), $this->sms->sent[1]['body']);
    }

    // ------------------------------------------------------------ 10.2.9 RR-SEG

    public function test_rr_seg_appendage_rides_when_it_adds_exactly_one_segment(): void
    {
        [$business, , $reward, $shareToken, $claimToken] = $this->fixture(
            ['name' => 'Harbor Street Barbers'],
            ['reward_text_snapshot' => '12345678901234567890123'] // 23 chars
        );

        (new SendRewardReadySms($reward->id))->handle($this->sms);

        $base = $this->baseBody($business, $reward, $claimToken);
        $this->assertCount(1, $this->sms->sent);
        $this->assertStringContainsString($this->plainAppendage($shareToken), $this->sms->sent[0]['body']);
        $this->assertSame(SmsSegments::count($base) + 1, SmsSegments::count($this->sms->sent[0]['body']));
    }

    public function test_rr_seg_appendage_is_dropped_when_it_would_add_two_segments(): void
    {
        // A non-GSM name (CJK characters are outside both GSM-7
        // tables) flips the body to UCS-2 (67-unit segments),
        // where the ~104-unit appendage can add two segments;
        // RR-SEG's +1 bound then drops it.
        [$business, , $reward, $shareToken, $claimToken] = $this->fixture(['name' => 'Cafe Luigi 東京 Pizza']);

        $base = $this->baseBody($business, $reward, $claimToken);
        $candidate = $base.$this->plainAppendage($shareToken);
        $this->assertGreaterThan(SmsSegments::count($base) + 1, SmsSegments::count($candidate), 'fixture precondition: candidate exceeds base + 1');

        (new SendRewardReadySms($reward->id))->handle($this->sms);

        $this->assertCount(1, $this->sms->sent);
        $this->assertSame($base, $this->sms->sent[0]['body']);
        $this->assertSame('reward_ready', SmsMessage::query()->sole()->template_key);
    }

    // ------------------------------------------------------------ 10.2.12 MEASURE query shape (Section 9.2)

    public function test_measure_window_counts_a_day_6_second_share_never_day_8_never_the_minting_referral(): void
    {
        [$business, $membership, $reward] = $this->fixture();
        (new SendRewardReadySms($reward->id))->handle($this->sms);
        $sentAt = SmsMessage::query()->where('template_key', 'reward_ready_reshare')->sole()->created_at;

        $mk = function (string $phone, Carbon $at) use ($membership) {
            $friend = World::customer($phone);
            [$referral] = World::referral($membership, $friend, ['state' => Referral::STATE_PENDING]);
            $referral->forceFill(['created_at' => $at])->save();

            return $referral;
        };
        $day6 = $mk('+12125550606', $sentAt->copy()->addDays(6));
        $mk('+12125550608', $sentAt->copy()->addDays(8));
        $mk('+12125550601', $sentAt->copy()->subDay()); // between vesting and send: outside

        $counted = Referral::query()
            ->where('referrer_membership_id', $membership->id)
            ->where('created_at', '>', $sentAt)
            ->where('created_at', '<=', $sentAt->copy()->addDays(7))
            ->pluck('id')
            ->all();

        $this->assertSame([$day6->id], $counted, 'the minting referral (created 21 days before the send) can never fall in the window');
    }

    // ------------------------------------------------------------ 10.2.10 claim page [RR-CP-01]

    public function test_claim_page_ready_live_flow_shows_the_reshare_block(): void
    {
        [, , , $shareToken, $claimToken] = $this->fixture();

        $html = $this->get(route('refer.claim.page', ['token' => $claimToken]))->assertOk()->getContent();

        $this->assertStringContainsString('Show this code to a staff member', $html);
        $this->assertStringContainsString('Share your link', $html);
        $this->assertStringContainsString('Your referral link', $html);
        $this->assertStringContainsString(route('refer.friend', ['token' => $shareToken]), $html);
        $this->assertStringNotContainsString('You came in through a friend yourself.', $html);
    }

    public function test_claim_page_ready_cohort_shows_f2r03_before_the_share_action(): void
    {
        [$business, $membership, , , $claimToken] = $this->fixture();
        $other = World::customer('+12125550333', 'Oth', 'Er');
        [$otherMembership] = World::membership($business, $other);
        World::referral($otherMembership, $membership->customer, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(40)]);

        $html = $this->get(route('refer.claim.page', ['token' => $claimToken]))->assertOk()->getContent();

        $this->assertStringContainsString('You came in through a friend yourself.', $html);
        $this->assertLessThan(
            strpos($html, 'Share your link'),
            strpos($html, 'You came in through a friend yourself.')
        );
    }

    public function test_claim_page_ready_paused_business_keeps_the_qr_and_drops_the_block(): void
    {
        [$business, , , , $claimToken] = $this->fixture();
        $this->pauseBusiness($business);

        $html = $this->get(route('refer.claim.page', ['token' => $claimToken]))->assertOk()->getContent();

        $this->assertStringContainsString('Show this code to a staff member', $html);
        $this->assertStringNotContainsString('Share your link', $html);
    }

    public function test_claim_page_removed_member_ready_reward_shows_no_block(): void
    {
        [, $membership, , , $claimToken] = $this->fixture();
        $membership->forceFill(['removed_at' => now(), 'share_token_state' => 'revoked'])->save();

        $html = $this->get(route('refer.claim.page', ['token' => $claimToken]))->assertOk()->getContent();

        $this->assertStringContainsString('Show this code to a staff member', $html);
        $this->assertStringNotContainsString('Share your link', $html);
    }

    public function test_claim_page_non_ready_states_carry_no_reshare_block(): void
    {
        [, , , , $waitingToken] = $this->fixture([], [
            'state' => Reward::STATE_PENDING_VESTING, 'available_at' => now()->addDays(3),
        ]);
        $waiting = $this->get(route('refer.claim.page', ['token' => $waitingToken]))->assertOk()->getContent();
        $this->assertStringNotContainsString('Share your link', $waiting);

        [, , , , $heldToken] = $this->fixture([], [
            'hold' => true, 'hold_rules' => ['device_signal_reuse'], 'held_at' => now(),
        ], '+12125550101');
        $held = $this->get(route('refer.claim.page', ['token' => $heldToken]))->assertOk()->getContent();
        $this->assertStringNotContainsString('Share your link', $held);

        [, , , , $deniedToken] = $this->fixture([], ['state' => Reward::STATE_DENIED], '+12125550202');
        $denied = $this->get(route('refer.claim.page', ['token' => $deniedToken]))->assertOk()->getContent();
        $this->assertStringNotContainsString('Share your link', $denied);
    }

    // ------------------------------------------------------------ 10.3 outsider gaming

    public function test_outsider_opening_the_prompted_share_link_lands_on_the_ordinary_friend_page(): void
    {
        [, , , $shareToken] = $this->fixture();

        $this->get(route('refer.friend', ['token' => $shareToken]))->assertOk();
    }

    // -------------------------------------------- F2R-03 dedicated pin (ID-08 Section 2.5 inheritance)

    public function test_f2r03_clause_requires_a_redeemed_provenance_and_rides_both_surfaces(): void
    {
        // A PENDING (never redeemed) arrival does NOT make the
        // cohort: the clause asserts a completed visit.
        [$business, $membership, $reward, $shareToken, $claimToken] = $this->fixture();
        $other = World::customer('+12125550333', 'Oth', 'Er');
        [$otherMembership] = World::membership($business, $other);
        World::referral($otherMembership, $membership->customer, ['state' => Referral::STATE_PENDING]);

        (new SendRewardReadySms($reward->id))->handle($this->sms);
        $this->assertCount(1, $this->sms->sent);
        $this->assertStringNotContainsString('You came in through a friend yourself.', $this->sms->sent[0]['body']);
        $this->assertStringContainsString('Share your link with a friend', $this->sms->sent[0]['body']);

        $html = $this->get(route('refer.claim.page', ['token' => $claimToken]))->assertOk()->getContent();
        $this->assertStringNotContainsString('You came in through a friend yourself.', $html);

        // The provenance referral redeems: the cohort form now
        // rides BOTH surfaces, clause first, before the action.
        Referral::query()
            ->where('referrer_membership_id', $otherMembership->id)
            ->where('referred_customer_id', $membership->customer_id)
            ->update(['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()]);

        $html = $this->get(route('refer.claim.page', ['token' => $claimToken]))->assertOk()->getContent();
        $this->assertStringContainsString('You came in through a friend yourself.', $html);
        $this->assertLessThan(strpos($html, 'Share your link'), strpos($html, 'You came in through a friend yourself.'));
    }

    // -------------------------------------------- gate racing: state flips between queue and send

    public function test_gate_racing_removal_and_opt_out_between_queue_and_send_are_honoured_at_send(): void
    {
        // The job is constructed (queued) while everything is
        // live; the membership is then removed and, for the
        // second reward, the phone opts out. handle() must read
        // the world as it is at send time.
        [$business, $membership, $reward, , $claimToken] = $this->fixture();
        $job = new SendRewardReadySms($reward->id);
        $membership->forceFill(['removed_at' => now(), 'share_token_state' => 'revoked'])->save();
        $job->handle($this->sms);
        $this->assertCount(1, $this->sms->sent);
        $this->assertSame($this->baseBody($business, $reward, $claimToken), $this->sms->sent[0]['body']);

        [, , $reward2] = $this->fixture([], [], '+12125550404');
        $job2 = new SendRewardReadySms($reward2->id);
        SmsOptOut::create(['phone_e164' => '+12125550404', 'opted_out_at' => now(), 'source' => 'carrier_stop']);
        $job2->handle($this->sms);
        $this->assertCount(1, $this->sms->sent, 'opt-out landing after queue suppresses the whole send');
    }

    // -------------------------------------------- dual-purpose boundary (pass Section 3.2 conditions)

    public function test_appendage_strings_stay_inside_the_consents_literal_scope(): void
    {
        $url = 'https://example.test/refer/r/TOKEN';
        $plain = MessageCopy::rewardReadyReshareAppendage($url, false);
        $cohort = MessageCopy::rewardReadyReshareAppendage($url, true);

        // Byte-exact against the copy inventory (Amendment 51).
        $this->assertSame(' Share your link with a friend: '.$url, $plain);
        $this->assertSame(' You came in through a friend yourself. Share your link: '.$url, $cohort);

        foreach ([$plain, $cohort] as $appendage) {
            // No offer, amount, urgency, deadline, or earning
            // promise may ever enter the appendage: any string
            // beyond the pass's exact wording bounds fails here.
            foreach (['earn', 'off', '%', '$', 'hurry', 'limited', 'deadline', 'expires', 'act now', 'offer'] as $banned) {
                $this->assertStringNotContainsStringIgnoringCase($banned, $appendage);
            }
            $this->assertStringNotContainsString("\u{2014}", $appendage, 'no em dashes');
            $this->assertSame('GSM-7', SmsSegments::encoding($appendage), 'appendage stays GSM-7');
        }
    }
}
