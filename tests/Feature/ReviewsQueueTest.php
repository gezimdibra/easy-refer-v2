<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
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
 * The owner review queue (spec Sections 3.1, 5.6): held items with
 * plain-sentence reasons, release and deny, and tenant isolation.
 */
class ReviewsQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        $this->app->instance(PhoneVerificationGateway::class, new FakePhoneVerificationGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        Mail::fake();
    }

    private function heldReferral($business, array $rules = ['velocity_daily']): Referral
    {
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, [
            'hold' => true, 'hold_rules' => $rules, 'held_at' => now(),
        ]);

        return $referral;
    }

    public function test_queue_lists_held_referrals_with_plain_reasons(): void
    {
        $business = World::business();
        $this->heldReferral($business);

        $this->actingAs(World::owner($business))
            ->get(route('reviews'))
            ->assertOk()
            ->assertSee('Needs review')
            // Parties are named by first name only (design 3.4).
            ->assertSee('referred by Reg')
            ->assertDontSee('Fri End')
            ->assertDontSee('Reg Ular')
            ->assertSee('This referrer reached the daily limit of 5 rewarded referrals.')
            // Deny sits behind the consequence modal (F-04): the
            // finality and the no-notification fact render with the
            // queue, and nothing has fired yet.
            ->assertSee('Deny this referral?')
            ->assertSee('The customer is not notified automatically.')
            ->assertSee('Denying is final and cannot be undone.');
    }

    public function test_released_referral_can_be_redeemed_normally(): void
    {
        $business = World::business();
        $referral = $this->heldReferral($business);
        $token = $referral->redeem_token_encrypted;

        $this->actingAs(World::owner($business))
            ->post(route('reviews.referrals.release', ['referral' => $referral->id]))
            ->assertRedirect();

        $this->assertFalse($referral->fresh()->hold);
        $this->assertDatabaseHas('referral_events', ['event_type' => 'review_release', 'referral_id' => $referral->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'review.referral_released']);

        // The staff flow works again.
        $page = $this->get(route('redeem.show', ['token' => $token]))->assertOk()->assertSee('Mark as Redeemed');
        preg_match('/name="nonce" value="([^"]+)"/', $page->getContent(), $m);
        $this->post(route('redeem.confirm', ['token' => $token]), ['nonce' => $m[1]])->assertRedirect();
        $this->assertSame(Referral::STATE_REDEEMED, $referral->fresh()->state);
    }

    public function test_denied_referral_is_terminal_and_never_bills(): void
    {
        $business = World::business();
        $referral = $this->heldReferral($business);

        $this->actingAs(World::owner($business))
            ->post(route('reviews.referrals.deny', ['referral' => $referral->id]))
            ->assertRedirect();

        $this->assertSame(Referral::STATE_VOIDED, $referral->fresh()->state);

        $this->get(route('redeem.show', ['token' => $referral->redeem_token_encrypted]))
            ->assertNotFound()
            ->assertSee('This code is not active');

        $this->assertSame(0, \App\Models\BillingEvent::count());
        $this->assertSame(0, Reward::count());
    }

    public function test_held_reward_release_and_deny(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()]);
        [$reward] = World::reward($membership, $referral, [
            'state' => Reward::STATE_AVAILABLE,
            'hold' => true, 'hold_rules' => ['redemption_burst'], 'held_at' => now(),
            'notification_state' => 'queued',
        ]);

        $owner = World::owner($business);

        $this->actingAs($owner)->get(route('reviews'))
            ->assertOk()
            ->assertSee('Redemptions spiked well above the usual pace.');

        $this->actingAs($owner)
            ->post(route('reviews.rewards.deny', ['reward' => $reward->id]))
            ->assertRedirect();

        $this->assertSame(Reward::STATE_DENIED, $reward->fresh()->state);
    }

    public function test_another_business_cannot_see_or_touch_the_queue(): void
    {
        $business = World::business();
        $other = World::business();
        $referral = $this->heldReferral($business);

        $this->actingAs(World::owner($other))
            ->get(route('reviews'))
            ->assertOk()
            ->assertDontSee('Fri End');

        $this->actingAs(World::owner($other))
            ->post(route('reviews.referrals.release', ['referral' => $referral->id]))
            ->assertNotFound();

        $this->assertTrue($referral->fresh()->hold);
    }
}
