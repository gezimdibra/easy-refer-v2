<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\Totp;
use Database\Seeders\TwoBusinessFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Two-factor authentication (spec Section 8.6; matrix AUTH-31,
 * AUTH-32, XT-17 to XT-19; finding G-1/FB-03). Enrollment and disable
 * sit behind a fresh password check; an active enrollment gates the
 * whole owner area behind the challenge until it is satisfied in
 * that session; recovery codes are shown once, stored hashed, and
 * single-use.
 */
class TwoFactorTest extends TestCase
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

    private function ownerB(): User
    {
        return User::where('email', TwoBusinessFixtureSeeder::B_EMAIL)->firstOrFail();
    }

    /**
     * Enroll owner A fully and return the plaintext recovery codes.
     *
     * @return list<string>
     */
    private function enrollOwnerA(): array
    {
        $user = $this->ownerA();

        $this->actingAs($user)->post(route('security.two-factor.start'), [
            'current_password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ])->assertRedirect(route('security.two-factor.confirm'));

        $user->refresh();

        $this->actingAs($user)->post(route('security.two-factor.confirm.store'), [
            'current_password' => TwoBusinessFixtureSeeder::A_PASSWORD,
            'code' => Totp::code((string) $user->two_factor_secret),
        ])->assertRedirect(route('security'));

        $codes = session()->get('two_factor_recovery_codes');

        $this->assertIsArray($codes);
        $this->assertCount((int) config('security.two_factor.recovery_code_count'), $codes);

        return $codes;
    }

    public function test_enrollment_is_blocked_without_a_fresh_password_check(): void
    {
        $user = $this->ownerA();

        $this->actingAs($user)->post(route('security.two-factor.start'), [
            'current_password' => 'not-the-password',
        ])->assertSessionHasErrors('current_password', null, 'two_factor');

        $this->assertNull($user->fresh()->two_factor_secret);
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_two_factor_panel_error_renders_once_under_its_own_form(): void
    {
        // T1-F2: a wrong current password on the two-factor start
        // form must print its error once, under the two-factor
        // panel only (its own error bag), not also under the
        // change-password panel above it.
        $user = $this->ownerA();

        $this->actingAs($user)->post(route('security.two-factor.start'), [
            'current_password' => 'not-the-password',
        ])->assertSessionHasErrors('current_password', null, 'two_factor');

        $page = $this->actingAs($user)->get(route('security'))->assertOk();
        $this->assertSame(
            1,
            substr_count($page->getContent(), 'The provided password is incorrect.'),
            'The two-factor panel error must render exactly once.'
        );
    }

    public function test_enrollment_stores_an_encrypted_secret_and_stays_inactive_until_confirmed(): void
    {
        $user = $this->ownerA();

        $this->actingAs($user)->post(route('security.two-factor.start'), [
            'current_password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ])->assertRedirect(route('security.two-factor.confirm'));

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertTrue($user->hasPendingTwoFactorEnrollment());
        $this->assertFalse($user->hasTwoFactorEnabled());

        // The database holds ciphertext, never the secret itself.
        $raw = DB::table('users')->where('id', $user->id)->value('two_factor_secret');
        $this->assertNotSame($user->two_factor_secret, $raw);

        // An unconfirmed enrollment does not gate sign-in.
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_the_confirmation_page_carries_the_provisioning_uri(): void
    {
        $user = $this->ownerA();

        $this->actingAs($user)->post(route('security.two-factor.start'), [
            'current_password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ]);

        $secret = (string) $user->fresh()->two_factor_secret;

        $this->actingAs($user->fresh())
            ->get(route('security.two-factor.confirm'))
            ->assertOk()
            ->assertSee('otpauth://totp/', false)
            ->assertSee($secret);
    }

    public function test_confirmation_with_a_wrong_code_activates_nothing(): void
    {
        $user = $this->ownerA();

        $this->actingAs($user)->post(route('security.two-factor.start'), [
            'current_password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ]);

        $this->actingAs($user)->post(route('security.two-factor.confirm.store'), [
            'current_password' => TwoBusinessFixtureSeeder::A_PASSWORD,
            'code' => '000000',
        ])->assertSessionHasErrors('code');

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
        $this->assertNull($user->fresh()->two_factor_recovery_codes);
    }

    public function test_confirmation_is_blocked_without_the_account_password(): void
    {
        $user = $this->ownerA();

        $this->actingAs($user)->post(route('security.two-factor.start'), [
            'current_password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ]);

        $user->refresh();
        $code = Totp::code((string) $user->two_factor_secret);

        // No password at all: the request itself is incomplete.
        $this->actingAs($user)->post(route('security.two-factor.confirm.store'), [
            'code' => $code,
        ])->assertSessionHasErrors('current_password');

        // The wrong password: the fresh check refuses, even with a
        // valid code, and the enrollment stays pending and codeless.
        $this->actingAs($user)->post(route('security.two-factor.confirm.store'), [
            'current_password' => 'not-the-password',
            'code' => $code,
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue($user->fresh()->hasPendingTwoFactorEnrollment());
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
        $this->assertNull($user->fresh()->two_factor_recovery_codes);
    }

    public function test_an_expired_pending_enrollment_cannot_be_confirmed_and_is_cleared(): void
    {
        $user = $this->ownerA();

        $this->actingAs($user)->post(route('security.two-factor.start'), [
            'current_password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ]);

        $user->refresh();
        $secret = (string) $user->two_factor_secret;

        // Age the pending enrollment past the configured window.
        $user->forceFill([
            'two_factor_pending_at' => now()->subSeconds((int) config('security.two_factor.pending_ttl_seconds') + 60),
        ])->save();

        $this->actingAs($user->fresh())->post(route('security.two-factor.confirm.store'), [
            'current_password' => TwoBusinessFixtureSeeder::A_PASSWORD,
            'code' => Totp::code($secret),
        ])->assertSessionHasErrors('code');

        // The stale secret is gone, nothing activated, no codes.
        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_pending_at);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertFalse($user->hasTwoFactorEnabled());
        $this->assertSame(0, AuditLog::where('action', 'owner.two_factor_enabled')->count());
    }

    public function test_confirmation_without_any_enrollment_is_refused(): void
    {
        $user = $this->ownerA();
        $service = app(TwoFactorService::class);

        try {
            $service->confirmEnrollment($user, '123456', TwoBusinessFixtureSeeder::A_PASSWORD);
            $this->fail('Confirmation with no enrollment in progress must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors());
        }

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
        $this->assertNull($user->fresh()->two_factor_recovery_codes);
    }

    public function test_a_pending_enrollment_with_no_recorded_start_is_treated_as_expired(): void
    {
        $user = $this->ownerA();
        $service = app(TwoFactorService::class);

        $service->startEnrollment($user, TwoBusinessFixtureSeeder::A_PASSWORD);
        $user->refresh();
        $secret = (string) $user->two_factor_secret;
        $user->forceFill(['two_factor_pending_at' => null])->save();

        try {
            $service->confirmEnrollment($user->fresh(), Totp::code($secret), TwoBusinessFixtureSeeder::A_PASSWORD);
            $this->fail('A pending enrollment with no recorded start must not confirm.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors());
        }

        $this->assertNull($user->fresh()->two_factor_secret);
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_confirmation_activates_and_shows_recovery_codes_once_stored_hashed(): void
    {
        $codes = $this->enrollOwnerA();
        $user = $this->ownerA();

        $this->assertTrue($user->hasTwoFactorEnabled());

        // Stored form: hashes only, inside the encrypted column.
        $stored = $user->two_factor_recovery_codes;
        $this->assertCount(count($codes), $stored);

        foreach ($codes as $plain) {
            $this->assertNotContains($plain, $stored);
            $normalized = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $plain));
            $this->assertContains(hash('sha256', $normalized), $stored);
        }

        // The security page shows the codes from the one-time flash
        // and never again after.
        $this->actingAs($user)->get(route('security'))->assertOk()->assertSee($codes[0]);
        $this->actingAs($user)->get(route('security'))->assertOk()->assertDontSee($codes[0]);

        $this->assertSame(1, AuditLog::where('action', 'owner.two_factor_enabled')->count());
    }

    public function test_an_enrolled_owner_must_pass_the_challenge_after_signing_in(): void
    {
        $this->enrollOwnerA();

        $this->post(route('logout'));

        $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ]);

        // Password alone no longer reaches the dashboard.
        $this->get(route('dashboard'))->assertRedirect(route('two-factor.challenge'));
        $this->get(route('security'))->assertRedirect(route('two-factor.challenge'));

        // A wrong code holds the gate and is audited.
        $this->post(route('two-factor.challenge.store'), ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->get(route('dashboard'))->assertRedirect(route('two-factor.challenge'));
        $this->assertSame(1, AuditLog::where('action', 'owner.two_factor_challenge_failed')->count());

        // A fresh code opens it (the confirmation code is spent:
        // codes are single-use, finding N-1).
        $user = $this->ownerA();
        $this->post(route('two-factor.challenge.store'), [
            'code' => Totp::code((string) $user->two_factor_secret, time() + Totp::PERIOD_SECONDS),
        ])->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'owner.two_factor_challenge_passed')->count());
    }

    public function test_a_recovery_code_opens_the_challenge_once_and_only_once(): void
    {
        $codes = $this->enrollOwnerA();

        $this->post(route('logout'));
        $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ]);

        $this->post(route('two-factor.challenge.store'), ['code' => $codes[0]])
            ->assertRedirect(route('dashboard'));

        $this->assertCount(
            count($codes) - 1,
            $this->ownerA()->two_factor_recovery_codes
        );

        // Second use of the same code, in a fresh sign-in, fails.
        $this->post(route('logout'));
        $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ]);

        $this->post(route('two-factor.challenge.store'), ['code' => $codes[0]])
            ->assertSessionHasErrors('code');
        $this->get(route('dashboard'))->assertRedirect(route('two-factor.challenge'));

        // The next unused code still works.
        $this->post(route('two-factor.challenge.store'), ['code' => $codes[1]])
            ->assertRedirect(route('dashboard'));
    }

    public function test_disable_is_blocked_without_a_fresh_password_check(): void
    {
        $this->enrollOwnerA();
        $user = $this->ownerA();

        $this->actingAs($user)->withSession(['auth.two_factor_passed' => true])
            ->post(route('security.two-factor.disable'), [
                'current_password' => 'not-the-password',
            ])->assertSessionHasErrors('current_password', null, 'two_factor');

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_disable_with_the_password_check_turns_two_factor_off(): void
    {
        $this->enrollOwnerA();
        $user = $this->ownerA();

        $this->actingAs($user)->withSession(['auth.two_factor_passed' => true])
            ->post(route('security.two-factor.disable'), [
                'current_password' => TwoBusinessFixtureSeeder::A_PASSWORD,
            ])->assertRedirect();

        $user->refresh();
        $this->assertFalse($user->hasTwoFactorEnabled());
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertSame(1, AuditLog::where('action', 'owner.two_factor_disabled')->count());

        // Sign-in is password-only again.
        $this->post(route('logout'));
        $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ])->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_only_the_owning_owner_can_enroll_confirm_or_disable(): void
    {
        // XT-17 to XT-19: the endpoints are session-scoped, so owner B
        // acting can only ever touch B's own enrollment, and anonymous
        // is turned away at the guard.
        $this->post(route('security.two-factor.start'), [
            'current_password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ])->assertRedirect(route('login'));

        $ownerB = $this->ownerB();

        $this->actingAs($ownerB)->post(route('security.two-factor.start'), [
            'current_password' => TwoBusinessFixtureSeeder::B_PASSWORD,
        ])->assertRedirect(route('security.two-factor.confirm'));

        $this->assertNull($this->ownerA()->two_factor_secret);
        $this->assertNotNull($ownerB->fresh()->two_factor_secret);

        $this->actingAs($ownerB)->post(route('security.two-factor.disable'), [
            'current_password' => TwoBusinessFixtureSeeder::B_PASSWORD,
        ])->assertRedirect();

        $this->assertNull($ownerB->fresh()->two_factor_secret);
        $this->assertNull($this->ownerA()->two_factor_secret);
    }

    public function test_enrollment_rotates_the_session_id(): void
    {
        config(['session.driver' => 'database']);

        $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ])->assertRedirect(route('dashboard'));

        $before = session()->getId();

        $this->post(route('security.two-factor.start'), [
            'current_password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ])->assertRedirect(route('security.two-factor.confirm'));

        $this->assertNotSame($before, session()->getId());
    }

    public function test_the_policy_flags_record_od9_option_a(): void
    {
        $this->assertFalse(config('security.two_factor.owner_required'));
        $this->assertTrue(config('security.two_factor.admin_required'));
    }

    public function test_the_confirmation_page_redirects_without_a_pending_enrollment(): void
    {
        $this->actingAs($this->ownerA())
            ->get(route('security.two-factor.confirm'))
            ->assertRedirect(route('security'));
    }

    public function test_the_challenge_page_renders_for_an_enrolled_owner(): void
    {
        $this->enrollOwnerA();

        $this->actingAs($this->ownerA())
            ->get(route('two-factor.challenge'))
            ->assertOk()
            ->assertSee('Two-factor verification');
    }

    public function test_a_recovery_code_cannot_be_consumed_when_none_are_stored(): void
    {
        $service = app(TwoFactorService::class);

        $this->assertFalse($service->consumeRecoveryCode($this->ownerA(), 'ABCDE-12345'));
        $this->assertNull($service->verifyChallenge($this->ownerA(), 'ABCDE-12345'));
    }
}
