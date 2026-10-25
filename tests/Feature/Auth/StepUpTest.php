<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\StepUpService;
use App\Services\Auth\TwoFactorService;
use App\Support\Totp;
use Database\Seeders\TwoBusinessFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Step-up authentication (spec Section 8.7; finding FB-03). The
 * email change is Phase 1's step-up-protected action: without a
 * verification inside the window it never executes; with one it
 * proceeds, and the window expires on schedule. A completed password
 * change also leaves the window fresh, because its in-request
 * current-password proof runs through the same service.
 */
class StepUpTest extends TestCase
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

    public function test_email_change_is_refused_without_a_fresh_step_up(): void
    {
        $user = $this->ownerA();

        $this->actingAs($user)
            ->post(route('security.email'), ['new_email' => 'new.a@example.test'])
            ->assertRedirect(route('security.step-up'));

        // Nothing changed while the gate held.
        $this->assertSame(TwoBusinessFixtureSeeder::A_EMAIL, $user->fresh()->email);
    }

    public function test_the_step_up_challenge_accepts_the_password_and_unlocks_the_change(): void
    {
        $user = $this->ownerA();

        $this->actingAs($user)
            ->post(route('security.email'), ['new_email' => 'new.a@example.test'])
            ->assertRedirect(route('security.step-up'));

        $this->actingAs($user)
            ->post(route('security.step-up.store'), ['password' => 'not-the-password'])
            ->assertSessionHasErrors('password');

        $this->assertSame(1, AuditLog::where('action', 'owner.step_up_failed')->count());

        $this->actingAs($user)
            ->post(route('security.step-up.store'), ['password' => TwoBusinessFixtureSeeder::A_PASSWORD])
            ->assertRedirect(route('security'));

        $this->assertSame(1, AuditLog::where('action', 'owner.step_up_verified')->count());

        $this->actingAs($user)
            ->post(route('security.email'), ['new_email' => 'new.a@example.test'])
            ->assertRedirect();

        $this->assertSame('new.a@example.test', $user->fresh()->email);
    }

    public function test_a_step_up_older_than_the_window_no_longer_counts(): void
    {
        $user = $this->ownerA();
        $window = (int) config('security.step_up.window_seconds');

        $this->actingAs($user)
            ->withSession([StepUpService::SESSION_KEY => now()->timestamp - $window - 1])
            ->post(route('security.email'), ['new_email' => 'new.a@example.test'])
            ->assertRedirect(route('security.step-up'));

        $this->assertSame(TwoBusinessFixtureSeeder::A_EMAIL, $user->fresh()->email);
    }

    public function test_the_step_up_challenge_accepts_a_totp_code_for_enrolled_owners(): void
    {
        $user = $this->ownerA();

        app(TwoFactorService::class)->startEnrollment($user, TwoBusinessFixtureSeeder::A_PASSWORD);
        $user->refresh();
        app(TwoFactorService::class)->confirmEnrollment($user, Totp::code((string) $user->two_factor_secret), TwoBusinessFixtureSeeder::A_PASSWORD);

        $this->actingAs($user->fresh())
            ->withSession(['auth.two_factor_passed' => true])
            ->post(route('security.step-up.store'), ['code' => Totp::code((string) $user->fresh()->two_factor_secret, time() + Totp::PERIOD_SECONDS)])
            ->assertRedirect(route('security'));

        $this->actingAs($user->fresh())
            ->withSession(['auth.two_factor_passed' => true])
            ->post(route('security.email'), ['new_email' => 'new.a@example.test'])
            ->assertRedirect();

        $this->assertSame('new.a@example.test', $user->fresh()->email);
    }

    #[Group('slow')]
    public function test_a_password_change_leaves_the_step_up_window_fresh(): void
    {
        $user = $this->ownerA();

        $this->actingAs($user)->put(route('security.password'), [
            'current_password' => TwoBusinessFixtureSeeder::A_PASSWORD,
            'password' => 'a brand new password 7',
            'password_confirmation' => 'a brand new password 7',
        ])->assertRedirect();

        // No separate challenge is demanded for the email change that
        // follows inside the window.
        $this->actingAs($user)
            ->post(route('security.email'), ['new_email' => 'new.a@example.test'])
            ->assertRedirect();

        $this->assertSame('new.a@example.test', $user->fresh()->email);
    }

    public function test_the_step_up_pages_are_owner_only(): void
    {
        $this->get(route('security.step-up'))->assertRedirect(route('login'));
        $this->post(route('security.step-up.store'), ['password' => 'x'])
            ->assertRedirect(route('login'));
        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
    }

    public function test_the_step_up_challenge_page_renders_for_the_owner(): void
    {
        $this->actingAs($this->ownerA())
            ->get(route('security.step-up'))
            ->assertOk()
            ->assertSee('Confirm it is you');
    }

    public function test_step_up_page_shows_plain_password_copy_without_two_factor(): void
    {
        // T3-F2: the challenge intro is owner-facing copy; no
        // Blade directive text may reach the rendered page.
        $response = $this->actingAs($this->ownerA())->get(route('security.step-up'));

        $response->assertOk();
        $response->assertSee('Re-enter your password.');
        $response->assertDontSee('@if');
        $response->assertDontSee('@endif');
        $response->assertDontSee('hasTwoFactorEnabled');
        $response->assertDontSee('authenticator app');
    }

    public function test_step_up_page_shows_authenticator_copy_with_two_factor(): void
    {
        $user = $this->ownerA();

        app(TwoFactorService::class)->startEnrollment($user, TwoBusinessFixtureSeeder::A_PASSWORD);
        $user->refresh();
        app(TwoFactorService::class)->confirmEnrollment($user, Totp::code((string) $user->two_factor_secret), TwoBusinessFixtureSeeder::A_PASSWORD);

        $response = $this->actingAs($user->fresh())
            ->withSession(['auth.two_factor_passed' => true])
            ->get(route('security.step-up'));

        $response->assertOk();
        $response->assertSee('Re-enter your password, or enter a code from your authenticator app.');
        $response->assertDontSee('@if');
        $response->assertDontSee('@endif');
        $response->assertDontSee('hasTwoFactorEnabled');
    }

    public function test_step_up_return_never_honors_a_foreign_session_value(): void
    {
        // T3-F3 allowlist, defense in depth: whatever lands in
        // the session, the challenge only ever returns the owner
        // to a local path or a same-host page; anything else
        // falls back to the security page.
        $user = $this->ownerA();

        foreach (['https://evil.example/phish', '//evil.example/phish'] as $foreign) {
            $this->actingAs($user)
                ->withSession(['step_up.return' => $foreign])
                ->post(route('security.step-up.store'), ['password' => TwoBusinessFixtureSeeder::A_PASSWORD])
                ->assertRedirect(route('security'));
        }

        $this->actingAs($user)
            ->withSession(['step_up.return' => '/auth/settings/billing'])
            ->post(route('security.step-up.store'), ['password' => TwoBusinessFixtureSeeder::A_PASSWORD])
            ->assertRedirect(route('settings.billing'));
    }

    public function test_step_up_return_ignores_the_challenge_page_itself(): void
    {
        $user = $this->ownerA();

        $this->actingAs($user)->from(route('security.step-up'))
            ->post(route('security.email'), ['new_email' => 'new.a@example.test'])
            ->assertRedirect(route('security.step-up'));

        $this->actingAs($user)
            ->post(route('security.step-up.store'), ['password' => TwoBusinessFixtureSeeder::A_PASSWORD])
            ->assertRedirect(route('security'));
    }

    public function test_an_empty_step_up_submission_fails_verification(): void
    {
        $user = $this->ownerA();

        $this->actingAs($user)
            ->post(route('security.step-up.store'), [])
            ->assertSessionHasErrors('password');

        $this->assertSame(1, AuditLog::where('action', 'owner.step_up_failed')->count());
    }
}
