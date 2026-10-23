<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\StepUpService;
use App\Services\Auth\TwoFactorService;
use App\Support\Totp;
use Database\Seeders\TwoBusinessFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TOTP codes are single-use (finding N-1; RFC 6238 Section 5.2).
 * A verified code records its timestep on the account, and any code
 * at or before that timestep is refused afterwards, in every path
 * that verifies codes: the enrollment confirmation, the sign-in
 * challenge, and the step-up challenge share the one consuming
 * routine, so a code spent in any of them is spent in all.
 */
class TwoFactorReplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TwoBusinessFixtureSeeder::class);
    }

    private function owner(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /**
     * Enroll a fixture owner through the service and return the
     * refreshed model. The confirmation consumes the current
     * timestep, as a real confirmation does.
     */
    private function enroll(string $email, string $password): User
    {
        $user = $this->owner($email);
        $service = app(TwoFactorService::class);

        $service->startEnrollment($user, $password);
        $user->refresh();
        $service->confirmEnrollment($user, Totp::code((string) $user->two_factor_secret), $password);

        return $user->fresh();
    }

    public function test_the_same_code_cannot_pass_the_sign_in_challenge_twice(): void
    {
        $user = $this->enroll(TwoBusinessFixtureSeeder::A_EMAIL, TwoBusinessFixtureSeeder::A_PASSWORD);
        $service = app(TwoFactorService::class);
        $next = Totp::code((string) $user->two_factor_secret, time() + Totp::PERIOD_SECONDS);

        $this->assertSame('totp', $service->verifyChallenge($user, $next));
        $this->assertNull($service->verifyChallenge($user->fresh(), $next));
    }

    public function test_the_same_code_cannot_pass_step_up_twice(): void
    {
        $user = $this->enroll(TwoBusinessFixtureSeeder::A_EMAIL, TwoBusinessFixtureSeeder::A_PASSWORD);
        $stepUp = app(StepUpService::class);
        $next = Totp::code((string) $user->two_factor_secret, time() + Totp::PERIOD_SECONDS);

        $this->assertTrue($stepUp->verifyCredential($user, null, $next));
        $this->assertFalse($stepUp->verifyCredential($user->fresh(), null, $next));
    }

    public function test_consumption_is_shared_across_the_challenge_and_step_up_paths(): void
    {
        $service = app(TwoFactorService::class);
        $stepUp = app(StepUpService::class);

        // Spent at the challenge, the code is dead for step-up.
        $userA = $this->enroll(TwoBusinessFixtureSeeder::A_EMAIL, TwoBusinessFixtureSeeder::A_PASSWORD);
        $codeA = Totp::code((string) $userA->two_factor_secret, time() + Totp::PERIOD_SECONDS);

        $this->assertSame('totp', $service->verifyChallenge($userA, $codeA));
        $this->assertFalse($stepUp->verifyCredential($userA->fresh(), null, $codeA));

        // Spent at step-up, the code is dead for the challenge.
        $userB = $this->enroll(TwoBusinessFixtureSeeder::B_EMAIL, TwoBusinessFixtureSeeder::B_PASSWORD);
        $codeB = Totp::code((string) $userB->two_factor_secret, time() + Totp::PERIOD_SECONDS);

        $this->assertTrue($stepUp->verifyCredential($userB, null, $codeB));
        $this->assertNull($service->verifyChallenge($userB->fresh(), $codeB));
    }

    public function test_an_older_window_code_is_refused_once_a_newer_one_is_consumed(): void
    {
        $user = $this->enroll(TwoBusinessFixtureSeeder::A_EMAIL, TwoBusinessFixtureSeeder::A_PASSWORD);
        $service = app(TwoFactorService::class);
        $secret = (string) $user->two_factor_secret;
        $now = time();

        // Spend the next window's code first (valid via drift).
        $this->assertTrue($service->verifyCode($user, Totp::code($secret, $now + Totp::PERIOD_SECONDS)));

        // The current and previous windows' codes are now behind the
        // consumed timestep, used or not, and both are refused.
        $this->assertFalse($service->verifyCode($user->fresh(), Totp::code($secret, $now)));
        $this->assertFalse($service->verifyCode($user->fresh(), Totp::code($secret, $now - Totp::PERIOD_SECONDS)));
    }

    public function test_the_confirmation_code_cannot_be_replayed_and_the_next_window_still_passes(): void
    {
        $user = $this->owner(TwoBusinessFixtureSeeder::A_EMAIL);
        $service = app(TwoFactorService::class);
        $now = time();

        $service->startEnrollment($user, TwoBusinessFixtureSeeder::A_PASSWORD);
        $user->refresh();
        $secret = (string) $user->two_factor_secret;
        $service->confirmEnrollment($user, Totp::code($secret, $now), TwoBusinessFixtureSeeder::A_PASSWORD);

        // The code that just confirmed the enrollment is spent.
        $this->assertFalse($service->verifyCode($user->fresh(), Totp::code($secret, $now)));

        // The next window's code is untouched and passes.
        $this->assertTrue($service->verifyCode($user->fresh(), Totp::code($secret, $now + Totp::PERIOD_SECONDS)));
    }

    public function test_a_challenge_code_cannot_be_replayed_in_a_second_sign_in(): void
    {
        $user = $this->enroll(TwoBusinessFixtureSeeder::A_EMAIL, TwoBusinessFixtureSeeder::A_PASSWORD);
        $code = Totp::code((string) $user->two_factor_secret, time() + Totp::PERIOD_SECONDS);

        $this->post(route('logout'));
        $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ]);
        $this->post(route('two-factor.challenge.store'), ['code' => $code])
            ->assertRedirect(route('dashboard'));

        $this->post(route('logout'));
        $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ]);
        $this->post(route('two-factor.challenge.store'), ['code' => $code])
            ->assertSessionHasErrors('code');
        $this->get(route('dashboard'))->assertRedirect(route('two-factor.challenge'));
    }
}
