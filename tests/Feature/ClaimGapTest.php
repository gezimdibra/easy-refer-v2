<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use App\Services\Referral\EnrollmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * RT-2 RE-SPECIFIED (owner rulings 2026-10-27 + 2026-10-29):
 * the 25-minute gap moved from claim to redemption. The claim
 * side has NO edge anymore: a claim completes immediately at
 * any timestamp relative to the referrer anchor, no wait
 * screen renders, and no claim_too_soon machinery exists.
 * The redemption-side enforcement is pinned in
 * GapAtRedemptionTest. (This file replaces the pre-2026-10-29
 * claim-gate spec in full; its boundary pair moved surfaces.)
 */
class ClaimGapTest extends TestCase
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

    /** @return array{0: Membership, 1: string} */
    private function referrerAged(Business $business, string $phone, int $secondsAgo): array
    {
        $customer = World::customer($phone, 'Reg', 'Ular');
        [$membership, $token] = World::membership($business, $customer);
        $membership->forceFill(['created_at' => now()->subSeconds($secondsAgo)])->saveQuietly();

        return [$membership->refresh(), $token];
    }

    /** @param array<string, mixed> $overrides */
    private function claim(Membership $referrerMembership, array $overrides = []): array
    {
        return app(EnrollmentService::class)->completeClaim($referrerMembership, array_merge([
            'first_name' => 'Jo',
            'last_name' => 'Kim',
            'phone_e164' => '+12125550212',
            'consent' => true,
        ], $overrides), '127.0.0.1', null);
    }

    public function test_c7_claim_boundary_pair_both_complete(): void
    {
        // The inversion pin (design pass C7): at anchor + 24:59
        // AND at anchor + 25:00 the claim returns 'created'.
        $business = World::business();
        [$referrer] = $this->referrerAged($business, '+12125550901', 3600);

        Carbon::setTestNow($referrer->created_at->copy()->addMinutes(24)->addSeconds(59));
        try {
            $early = $this->claim($referrer);
        } finally {
            Carbon::setTestNow();
        }
        $this->assertSame('created', $early['kind']);
        $this->assertNotNull($early['referral']);
        $this->assertSame(Referral::STATE_PENDING, $early['referral']->state);

        [$referrer2] = $this->referrerAged($business, '+12125550902', 3600);
        Carbon::setTestNow($referrer2->created_at->copy()->addMinutes(25));
        try {
            $atEdge = $this->claim($referrer2, ['phone_e164' => '+12125550213']);
        } finally {
            Carbon::setTestNow();
        }
        $this->assertSame('created', $atEdge['kind']);
        $this->assertSame(2, Referral::count());
        $this->assertDatabaseMissing('referral_events', ['event_type' => 'claim_too_soon']);
    }

    public function test_claim_seconds_after_the_anchor_completes(): void
    {
        $business = World::business();
        [$referrer] = $this->referrerAged($business, '+12125550903', 5);

        $done = $this->claim($referrer);

        $this->assertSame('created', $done['kind']);
        $this->assertSame(1, Referral::count());
        $this->assertSame(1, (int) $referrer->refresh()->referral_count);
    }

    public function test_claim_long_after_the_gap_completes(): void
    {
        $business = World::business();
        [$referrer] = $this->referrerAged($business, '+12125550904', 60 * 60 * 24 * 3);

        $this->assertSame('created', $this->claim($referrer)['kind']);
    }

    public function test_dedupe_still_binds_a_repeat_claim_inside_the_window(): void
    {
        $business = World::business();
        [$oldReferrer] = $this->referrerAged($business, '+12125550906', 60 * 60 * 48);
        $first = $this->claim($oldReferrer);
        $this->assertSame('created', $first['kind']);

        [$freshReferrer] = $this->referrerAged($business, '+12125550907', 5);
        $done = $this->claim($freshReferrer);

        $this->assertSame('existing', $done['kind']);
        $this->assertSame($first['referral']->id, $done['referral']->id);
    }

    public function test_http_claim_inside_the_former_gap_lands_on_the_visit_page_with_no_wait_copy(): void
    {
        $business = World::business();
        [, $shareToken] = $this->referrerAged($business, '+12125550909', 10 * 60);

        $this->carryFlowCookie($this->get(route('refer.friend', ['token' => $shareToken]))->assertOk());
        $this->carryFlowCookie($this->post(route('refer.friend.claim', ['token' => $shareToken]), [
            'first_name' => 'Jo', 'last_name' => 'Kim', 'phone' => '2125550212', 'consent' => '1', 'send_link' => '1',
        ])->assertRedirect(route('refer.verify')));

        $response = $this->post(route('refer.verify.check'), ['code' => '123456'])
            ->assertRedirect(route('refer.redeem.page', ['token' => Referral::first()->redeem_token_encrypted]));

        $this->assertSame(1, Referral::count());
        foreach (['Just a short wait', 'unlocks at', 'Your details are saved', 'We will text you when your reward unlocks', 'Your wait is over', 'Finish claiming my reward'] as $absent) {
            $response->assertDontSee($absent);
        }
        $this->assertDatabaseMissing('referral_events', ['event_type' => 'claim_too_soon']);
        $this->assertDatabaseMissing('sms_messages', ['template_key' => 'claim_unlock']);
    }

    public function test_the_one_hour_signal_still_only_records_at_redemption(): void
    {
        $business = World::business();
        [$referrer] = $this->referrerAged($business, '+12125550911', 26 * 60);
        $done = $this->claim($referrer);
        $this->assertSame('created', $done['kind']);
        $referral = $done['referral'];

        // Move past the 25-minute redemption window (anchor is
        // 26 minutes old already) — redemption proceeds and the
        // one-hour signal records without gating.
        $show = $this->get(route('redeem.show', ['token' => $referral->redeem_token_encrypted]))->assertOk();
        preg_match('/name="nonce" value="([^"]+)"/', $show->getContent(), $m);
        $this->assertNotEmpty($m);

        $this->post(route('redeem.confirm', ['token' => $referral->redeem_token_encrypted]), ['nonce' => $m[1]])
            ->assertRedirect(route('redeem.show', ['token' => $referral->redeem_token_encrypted]));

        $referral->refresh();
        $this->assertSame(Referral::STATE_REDEEMED, $referral->state);
        $this->assertSame(1, \App\Models\BillingEvent::where('referral_id', $referral->id)->count());
        $reward = $referral->reward;
        $this->assertNotNull($reward);
        $this->assertSame(\App\Models\Reward::STATE_PENDING_VESTING, $reward->state);
    }
}
