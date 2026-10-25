<?php

namespace Tests\Feature\Owner;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\World;
use Tests\TestCase;

/**
 * SV-FIX-01: the account email and its verification state are
 * visible where identity is managed (state-visibility-audit.md
 * Section 1; copy inventory Amendment 21). One shared partial
 * renders the address, the pill, the consequence, and the
 * resend action on Settings -> Account, and the same pill state
 * renders inline on Security so the surfaces cannot drift.
 */
class AccountEmailStateTest extends TestCase
{
    use RefreshDatabase;

    private function unverifiedOwner()
    {
        $business = World::business();
        $owner = World::owner($business);
        $owner->forceFill(['email_verified_at' => null])->save();

        return $owner->fresh();
    }

    private function verifiedOwner()
    {
        // World::owner's row does not carry the timestamp (it is
        // not mass-assignable), so pin the verified state the
        // same explicit way the unverified helper does.
        $business = World::business();
        $owner = World::owner($business);
        $owner->forceFill(['email_verified_at' => now()])->save();

        return $owner->fresh();
    }

    public function test_unverified_account_panel_shows_address_pill_consequence_and_resend(): void
    {
        $owner = $this->unverifiedOwner();

        $page = $this->actingAs($owner)->get(route('settings.account'));
        $page->assertOk();
        $page->assertSee('Account email');
        $page->assertSee($owner->email);
        $page->assertSee('pill-warning', false);
        $page->assertSee('Not verified');
        $page->assertSee('Your QR code and billing stay off until this email is verified.');
        $page->assertSee('action="'.route('verification.send').'"', false);
        $page->assertSee('Resend verification email');
        $page->assertSee('Change email');
        $page->assertSee('href="'.route('security').'"', false);
    }

    public function test_verified_account_panel_shows_verified_and_no_resend_form(): void
    {
        $owner = $this->verifiedOwner();

        $page = $this->actingAs($owner)->get(route('settings.account'));
        $page->assertOk();
        $page->assertSee($owner->email);
        $page->assertSee('pill-success', false);
        $page->assertSee('Verified');
        $page->assertDontSee('Not verified');
        $page->assertDontSee('Resend verification email');
        $page->assertDontSee('action="'.route('verification.send').'"', false);
        $page->assertSee('Change email');
    }

    public function test_security_shows_the_same_pill_state_as_account(): void
    {
        $owner = $this->unverifiedOwner();

        $this->actingAs($owner)->get(route('security'))
            ->assertOk()
            ->assertSee('pill-warning', false)
            ->assertSee('Not verified')
            ->assertSee('Resend verification email');

        $verified = $this->verifiedOwner();

        $this->actingAs($verified)->get(route('security'))
            ->assertOk()
            ->assertSee('pill-success', false)
            ->assertSee('Verified')
            ->assertDontSee('Not verified')
            ->assertDontSee('Resend verification email');
    }

    public function test_security_panel_carries_no_self_referential_change_email_link_and_one_consequence(): void
    {
        // F-09 (Train 1 fix round): the shared partial's actions
        // block rendered a "Change email" link pointing at the
        // Security page itself, directly above the change-email
        // form, and the panel intro restated the consequence in
        // different words. Render-and-count: in the Security
        // context the partial contributes no Change email anchor
        // at all, and the consequence is stated exactly once,
        // in the partial's wording.
        $owner = $this->unverifiedOwner();

        $page = $this->actingAs($owner)->get(route('security'));
        $page->assertOk();
        $html = $page->getContent();

        $this->assertSame(0, substr_count($html, '>Change email</a>'));
        $this->assertSame(1, substr_count($html, 'Your QR code and billing stay off until this email is verified.'));
        $this->assertStringNotContainsString('stay off until that link is used', $html);

        $verified = $this->verifiedOwner();

        $html = $this->actingAs($verified)->get(route('security'))->assertOk()->getContent();

        $this->assertSame(0, substr_count($html, '>Change email</a>'));
        $this->assertSame(0, substr_count($html, 'Your QR code and billing stay off until this email is verified.'));
        $this->assertStringNotContainsString('stay off until that link is used', $html);
    }

    public function test_account_panel_keeps_exactly_one_change_email_action_and_one_consequence(): void
    {
        // The other half of the F-09 render-and-count check: on
        // Account (the context the partial's actions belong to)
        // each action label appears at most once per panel.
        $owner = $this->unverifiedOwner();

        $html = $this->actingAs($owner)->get(route('settings.account'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '>Change email</a>'));
        $this->assertSame(1, substr_count($html, 'Your QR code and billing stay off until this email is verified.'));
        $this->assertSame(1, substr_count($html, '>Resend verification email</button>'));

        $verified = $this->verifiedOwner();

        $html = $this->actingAs($verified)->get(route('settings.account'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '>Change email</a>'));
        $this->assertSame(0, substr_count($html, 'Resend verification email'));
    }

    public function test_resend_from_the_account_context_flashes_the_shared_confirmation(): void
    {
        Notification::fake();
        $owner = $this->unverifiedOwner();

        $this->actingAs($owner)
            ->from(route('settings.account'))
            ->post(route('verification.send'))
            ->assertRedirect(route('settings.account'))
            ->assertSessionHas('status', 'verification-link-sent');

        Notification::assertSentTo($owner, VerifyEmail::class);

        $this->actingAs($owner)
            ->withSession(['status' => 'verification-link-sent'])
            ->get(route('settings.account'))
            ->assertOk()
            ->assertSee('A new verification link is on its way to your email address.');
    }
}
