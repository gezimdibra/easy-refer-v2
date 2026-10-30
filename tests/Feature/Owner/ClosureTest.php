<?php

namespace Tests\Feature\Owner;

use App\Models\AuditLog;
use App\Models\Referral;
use App\Models\Reward;
use App\Services\Auth\StepUpService;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Account closure (spec Section 5.5, S3-15/EC-42, DASH-13):
 * step-up gated, consequences stated first, pending referrals
 * voided with timeline events, minted rewards still claimable
 * through the wind-down, enrollment stopped, and the owner area
 * read-only afterwards.
 */
class ClosureTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        $this->app->instance(PhoneVerificationGateway::class, new FakePhoneVerificationGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        Mail::fake();
    }

    public function test_closure_requires_a_fresh_step_up(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('settings.account.close'), ['confirm' => '1'])
            ->assertRedirect(route('security.step-up'));

        $this->assertSame('active', $business->fresh()->status);
    }

    public function test_closure_runs_the_specced_consequences(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550411', 'Ana', 'Ray'));
        [$pending] = World::referral($membership, World::customer('+12125550412', 'Fay', 'One'));
        [$redeemed] = World::referral($membership, World::customer('+12125550413', 'Gil', 'Two'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
        ]);
        [$reward, $claimPlain] = World::reward($membership, $redeemed);

        $this->actingAs($owner)
            ->withSession([StepUpService::SESSION_KEY => now()->timestamp])
            ->post(route('settings.account.close'), ['confirm' => '1'])
            ->assertRedirect(route('dashboard'));

        $fresh = $business->fresh();
        $this->assertSame('closed', $fresh->status);
        $this->assertNotNull($fresh->closed_at);
        $this->assertSame(Referral::STATE_VOIDED, $pending->fresh()->state);
        $this->assertTrue(\App\Models\ReferralEvent::query()
            ->where('referral_id', $pending->id)
            ->where('event_type', 'referral_voided')
            ->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'business.closed')->exists());

        // The minted reward is untouched and still claimable:
        // its claim page renders and the staff confirm completes.
        $this->assertSame(Reward::STATE_AVAILABLE, $reward->fresh()->state);
        $this->get(route('refer.claim.page', ['token' => $claimPlain]))->assertOk();

        $page = $this->get(route('redeem.show', ['token' => $claimPlain]))->assertOk();
        preg_match('/name="nonce" value="([^"]+)"/', $page->getContent(), $m);
        $this->assertNotEmpty($m);
        $this->post(route('redeem.confirm', ['token' => $claimPlain]), ['nonce' => $m[1]]);
        $this->assertSame(Reward::STATE_CLAIMED, $reward->fresh()->state);
    }

    public function test_wind_down_ends_after_thirty_days(): void
    {
        // RDM-29, second leg (verifier blocker): minted rewards
        // stay claimable for exactly the 30-day wind-down; after
        // it, they expire and the claim links answer generically.
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550421', 'Ana', 'Ray'));
        [$redeemed] = World::referral($membership, World::customer('+12125550422', 'Fay', 'One'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
        ]);
        [$reward, $claimPlain] = World::reward($membership, $redeemed);
        [$secondRedeemed] = World::referral($membership, World::customer('+12125550423', 'Gil', 'Two'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
        ]);
        [$secondReward, $secondClaimPlain] = World::reward($membership, $secondRedeemed);

        $this->actingAs($owner)
            ->withSession([StepUpService::SESSION_KEY => now()->timestamp])
            ->post(route('settings.account.close'), ['confirm' => '1']);

        // Day 29: still inside the window, the claim completes.
        $business->forceFill(['closed_at' => now()->subDays(29)])->save();
        $this->get(route('refer.claim.page', ['token' => $claimPlain]))->assertOk();
        $page = $this->get(route('redeem.show', ['token' => $claimPlain]))->assertOk();
        preg_match('/name="nonce" value="([^"]+)"/', $page->getContent(), $m);
        $this->post(route('redeem.confirm', ['token' => $claimPlain]), ['nonce' => $m[1]]);
        $this->assertSame(Reward::STATE_CLAIMED, $reward->fresh()->state);

        // Day 31: the window has run out. The second reward's
        // claim page and staff page both answer generically, and
        // the reward itself expires on read.
        $business->forceFill(['closed_at' => now()->subDays(31)])->save();
        $this->get(route('refer.claim.page', ['token' => $secondClaimPlain]))->assertNotFound();
        $this->get(route('redeem.show', ['token' => $secondClaimPlain]))->assertNotFound();
        $this->assertSame(Reward::STATE_EXPIRED, $secondReward->fresh()->state);
    }

    public function test_closed_owner_with_two_factor_can_finish_signing_in(): void
    {
        // Security verifier F-02: the closed-business middleware
        // must not intercept the two-factor challenge itself, or
        // a closed business's owner with 2FA is locked out of the
        // whole wind-down.
        $business = World::business();
        $owner = World::owner($business);
        $owner->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ])->save();

        // Close while signed in with the challenge already
        // passed for this session (a real owner closing their
        // account has passed it to be here at all).
        $this->actingAs($owner)
            ->withSession([
                StepUpService::SESSION_KEY => now()->timestamp,
                'auth.two_factor_passed' => true,
            ])
            ->post(route('settings.account.close'), ['confirm' => '1']);
        $this->assertSame('closed', $business->fresh()->status);
        $this->actingAs($owner)->post(route('logout'));

        $this->post(route('login'), [
            'identifier' => $owner->email,
            'password' => 'WorldPassword01!',
        ]);
        $this->get(route('dashboard'))->assertRedirect(route('two-factor.challenge'));

        $this->post(route('two-factor.challenge.store'), [
            'code' => \App\Support\Totp::code('JBSWY3DPEHPK3PXP'),
        ])->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))->assertOk()->assertSee('Your program has ended.');
    }

    public function test_closed_business_is_read_only_and_enrollment_stops(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [, $qrPlain] = World::qr($business);

        $this->actingAs($owner)
            ->withSession([StepUpService::SESSION_KEY => now()->timestamp])
            ->post(route('settings.account.close'), ['confirm' => '1']);

        // Reads still work (the wind-down login).
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('Your program has ended.');
        $this->actingAs($owner)->get(route('customers.index'))->assertOk();

        // Writes are refused.
        $this->actingAs($owner)->post(route('settings.profile.update'), [
            'name' => 'Nope',
            'contact_email' => 'x@example.test',
            'contact_phone' => '2125550414',
            'street' => '1 Street',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
            'country' => 'US',
        ])->assertRedirect(route('dashboard'));
        $this->assertNotSame('Nope', $business->fresh()->name);

        // The public QR landing is the generic page.
        $this->get(route('refer.landing', ['token' => $qrPlain]))->assertNotFound();
    }
}
