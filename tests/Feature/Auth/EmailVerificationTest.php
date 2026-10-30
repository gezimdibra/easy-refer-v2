<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\VerifyEmailNotification as VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Spec Section 8.5: unverified owners can log in and see a persistent
 * gate, but the signed link is what verifies, and the business row
 * mirrors the state.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_owner_sees_the_verification_gate_on_the_dashboard(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Verify your email address');
    }

    public function test_verified_owner_sees_no_gate(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Verify your email address');
    }

    public function test_resend_sends_a_fresh_verification_email(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->post(route('verification.send'))
            ->assertSessionHas('status', 'verification-link-sent');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_signed_link_verifies_the_owner_and_mirrors_the_business(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        // P4 (OB-U2): while onboarding is open, the verification
        // transition returns into the flow's Verify step, ahead
        // of the BU-5 billing detour.
        $this->actingAs($user)->get($url)->assertRedirect(route('onboarding.step', 'verify'));

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertNotNull($user->business->fresh()->email_verified_at);
        $this->assertTrue(AuditLog::query()->where('action', 'owner.email_verified')->where('user_id', $user->id)->exists());
    }

    public function test_signed_link_sends_a_business_already_in_billing_to_the_dashboard(): void
    {
        // Past the flow (completion stamped) and already in
        // billing: the dashboard, as before.
        $user = User::factory()->unverified()->create();
        $user->business->forceFill(['billing_status' => 'active', 'onboarding_completed_at' => now()])->save();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect(route('dashboard').'?verified=1');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_signed_link_after_onboarding_with_billing_none_keeps_the_bu5_detour(): void
    {
        // RE-DERIVED (activation redesign, census C-36,
        // 2026-10-30): BU-5's old premise — billing not
        // started, so detour to billing — is retired with the
        // card leg. A cardless post-flow business with free
        // visits left is LIVE and owes nothing: no detour.
        $user = User::factory()->unverified()->create();
        $user->business->forceFill([
            'onboarding_completed_at' => now(),
            'friend_reward_text' => '$10 off your first visit',
        ])->save();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect(route('dashboard').'?verified=1');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        // The detour survives in exactly one state: the
        // allowance is exhausted, so the card is the thing
        // keeping the code off. Five counted visits on the
        // ledger, then the same link routes to billing.
        $user2 = User::factory()->unverified()->create();
        $business2 = $user2->business;
        $business2->forceFill([
            'onboarding_completed_at' => now(),
            'friend_reward_text' => '$10 off your first visit',
        ])->save();
        $evReferrer = \Tests\Support\World::customer('+12125551201', 'Reg', 'Ular');
        [$evMembership] = \Tests\Support\World::membership($business2, $evReferrer);
        for ($i = 0; $i < 5; $i++) {
            $evFriend = \Tests\Support\World::customer('+1212555121'.(string) $i, 'Fri', 'End');
            [$evReferral] = \Tests\Support\World::referral($evMembership, $evFriend);
            \App\Models\BillingEvent::create([
                'business_id' => $business2->id,
                'referral_id' => $evReferral->id,
                'amount_cents' => 200,
                'coverage' => 'billable',
            ]);
        }

        $url2 = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user2->id,
            'hash' => sha1($user2->getEmailForVerification()),
        ]);

        $this->actingAs($user2)->get($url2)->assertRedirect(route('settings.billing'));
        $this->assertTrue($user2->fresh()->hasVerifiedEmail());
    }

    public function test_repeat_click_after_verification_goes_to_the_dashboard_not_billing(): void
    {
        // Route-once (BU-5): the billing detour belongs to the
        // verification transition itself. An owner who is already
        // verified and clicks the link again lands on the
        // dashboard, even while billing is still 'none'.
        $user = User::factory()->create();
        $this->assertSame('none', $user->business->billing_status);

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect(route('dashboard').'?verified=1');
    }

    public function test_link_with_a_tampered_hash_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1('someone-else@example.test'),
        ]);

        $this->actingAs($user)->get($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }
}
