<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\EmailChangedNotification;
use App\Services\Auth\StepUpService;
use Database\Seeders\TwoBusinessFixtureSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Account email change (spec Section 8.5; matrix AUTH-30; finding
 * G-2). The new address is unverified until its link is used, a
 * notification goes to the old address, and the verification gate
 * reapplies in the meantime.
 */
class EmailChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TwoBusinessFixtureSeeder::class);
    }

    private function ownerA(): User
    {
        return User::where('email', TwoBusinessFixtureSeeder::A_EMAIL)->firstOrFail();
    }

    /**
     * Email change is a step-up-protected action (spec Section 8.7);
     * these tests exercise the flow itself, so they arrive with a
     * fresh step-up marker. StepUpTest covers the gate.
     */
    private function withFreshStepUp(): static
    {
        return $this->withSession([StepUpService::SESSION_KEY => now()->timestamp]);
    }

    public function test_an_owner_can_change_their_email_and_the_gate_reapplies(): void
    {
        Notification::fake();
        $user = $this->ownerA();

        $this->withFreshStepUp()->actingAs($user)
            ->post(route('security.email'), ['new_email' => 'new.a@example.test'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $user->refresh();

        $this->assertSame('new.a@example.test', $user->email);
        $this->assertSame('new.a@example.test', $user->canonical_email);
        $this->assertNull($user->email_verified_at);

        // The business mirror follows the account.
        $this->assertSame('new.a@example.test', $user->business->contact_email);
        $this->assertNull($user->business->email_verified_at);

        // A verification link went to the new address.
        Notification::assertSentTo($user, VerifyEmail::class);

        // A notification went to the OLD address.
        Notification::assertSentOnDemand(
            EmailChangedNotification::class,
            fn (EmailChangedNotification $notification, array $channels, object $notifiable) => $notifiable instanceof AnonymousNotifiable
                && ($notifiable->routes['mail'] ?? null) === TwoBusinessFixtureSeeder::A_EMAIL
                && $notification->newEmail === 'new.a@example.test'
        );

        // The verification gate is back on the dashboard.
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Verify your email address.');

        $this->assertSame(1, AuditLog::where('action', 'owner.email_change_requested')->count());
    }

    public function test_using_the_link_verifies_the_new_address_and_lifts_the_gate(): void
    {
        $user = $this->ownerA();

        $this->withFreshStepUp()->actingAs($user)
            ->post(route('security.email'), ['new_email' => 'new.a@example.test'])
            ->assertRedirect();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1('new.a@example.test'),
        ]);

        $this->actingAs($user->fresh())->get($url)->assertRedirect(route('dashboard').'?verified=1');

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->business->email_verified_at);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Verify your email address.');
    }

    public function test_an_email_belonging_to_another_account_is_refused(): void
    {
        $user = $this->ownerA();

        $this->withFreshStepUp()->actingAs($user)
            ->post(route('security.email'), ['new_email' => TwoBusinessFixtureSeeder::B_EMAIL])
            ->assertSessionHasErrors('new_email');

        // A plus-tagged variant of the other account's address is the
        // same canonical identity and is refused the same way.
        $this->withFreshStepUp()->actingAs($user)
            ->post(route('security.email'), ['new_email' => 'owner.b+tag@example.test'])
            ->assertSessionHasErrors('new_email');

        $this->assertSame(TwoBusinessFixtureSeeder::A_EMAIL, $user->fresh()->email);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_changing_to_the_current_email_is_refused(): void
    {
        $user = $this->ownerA();

        $this->withFreshStepUp()->actingAs($user)
            ->post(route('security.email'), ['new_email' => 'Owner.A@Example.Test'])
            ->assertSessionHasErrors('new_email');
    }

    public function test_anonymous_visitors_cannot_change_an_email(): void
    {
        $this->post(route('security.email'), ['new_email' => 'x@example.test'])
            ->assertRedirect(route('login'));
    }

    public function test_the_other_owner_cannot_change_this_owners_email(): void
    {
        // The endpoint is session-scoped: owner B acting can only ever
        // change B's own address (XT shape for session-scoped routes).
        $ownerB = User::where('email', TwoBusinessFixtureSeeder::B_EMAIL)->firstOrFail();

        $this->withFreshStepUp()->actingAs($ownerB)
            ->post(route('security.email'), ['new_email' => 'b-new@example.test'])
            ->assertRedirect();

        $this->assertSame(TwoBusinessFixtureSeeder::A_EMAIL, $this->ownerA()->email);
        $this->assertSame('b-new@example.test', $ownerB->fresh()->email);
    }
}
