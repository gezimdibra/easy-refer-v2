<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
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

        // The factory business has billing_status 'none', so the
        // verification transition routes to the billing page's
        // setup state (BU-5), not the dashboard.
        $this->actingAs($user)->get($url)->assertRedirect(route('settings.billing'));

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertNotNull($user->business->fresh()->email_verified_at);
        $this->assertTrue(AuditLog::query()->where('action', 'owner.email_verified')->where('user_id', $user->id)->exists());
    }

    public function test_signed_link_sends_a_business_already_in_billing_to_the_dashboard(): void
    {
        $user = User::factory()->unverified()->create();
        $user->business->forceFill(['billing_status' => 'active'])->save();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect(route('dashboard').'?verified=1');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
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
