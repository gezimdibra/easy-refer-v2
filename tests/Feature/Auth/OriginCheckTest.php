<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\TwoBusinessFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Origin/Referer defense in depth on sensitive endpoints (spec
 * Section 8.4; review finding M-4). Browsers always send one of the
 * headers on state-changing requests; a foreign value is refused even
 * with a valid CSRF token.
 */
class OriginCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TwoBusinessFixtureSeeder::class);
    }

    public function test_a_foreign_origin_is_refused_on_login(): void
    {
        $this->withHeaders(['Origin' => 'https://evil.example'])
            ->post(route('login'), [
                'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
                'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
            ])
            ->assertForbidden();

        $this->assertGuest();
    }

    public function test_a_foreign_referer_is_refused_when_no_origin_is_sent(): void
    {
        $this->withHeaders(['Referer' => 'https://evil.example/phish'])
            ->post(route('login'), [
                'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
                'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
            ])
            ->assertForbidden();
    }

    public function test_a_matching_origin_is_accepted(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost'])
            ->post(route('login'), [
                'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
                'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
            ])
            ->assertRedirect(route('dashboard'));
    }

    public function test_a_matching_origin_with_an_explicit_port_is_accepted(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:80'])
            ->post(route('login'), [
                'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
                'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
            ])
            ->assertRedirect(route('dashboard'));
    }

    public function test_no_origin_headers_passes_for_non_browser_calls(): void
    {
        $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => 'wrong-password-xx',
        ])->assertSessionHasErrors('identifier');
    }

    public function test_a_foreign_origin_is_refused_on_password_change(): void
    {
        $user = User::where('email', TwoBusinessFixtureSeeder::A_EMAIL)->firstOrFail();

        $this->actingAs($user)
            ->withHeaders(['Origin' => 'https://evil.example'])
            ->put(route('security.password'), [
                'current_password' => TwoBusinessFixtureSeeder::A_PASSWORD,
                'password' => 'a brand new password 7',
                'password_confirmation' => 'a brand new password 7',
            ])
            ->assertForbidden();
    }

    public function test_a_garbage_origin_is_refused(): void
    {
        $this->withHeaders(['Origin' => 'not a url'])
            ->post(route('login'), [
                'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
                'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
            ])
            ->assertForbidden();
    }

    public function test_safe_methods_carry_no_origin_check_by_design(): void
    {
        // The check is attached to state-changing routes only; reads
        // are not origin-checked (spec Section 8.4 lists the sensitive
        // endpoints, all of them writes).
        $this->withHeaders(['Origin' => 'https://evil.example'])
            ->get(route('login'))
            ->assertOk();
    }

    public function test_the_check_compares_against_app_url_not_the_request_host(): void
    {
        // Finding L-1: under Host-header poisoning the request's own
        // host is attacker-chosen; an Origin matching the configured
        // APP_URL must still pass, because APP_URL is the target.
        $this->withHeaders([
            'Host' => 'attacker.example',
            'Origin' => 'http://localhost',
        ])->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ])->assertRedirect(route('dashboard'));
    }

    public function test_an_origin_matching_only_the_poisoned_host_is_refused(): void
    {
        // The mirror image: an Origin that matches the attacker's
        // Host header but not APP_URL is refused.
        $this->withHeaders([
            'Host' => 'attacker.example',
            'Origin' => 'http://attacker.example',
        ])->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ])->assertForbidden();

        $this->assertGuest();
    }

    public function test_a_foreign_origin_is_refused_on_logout(): void
    {
        $user = User::where('email', TwoBusinessFixtureSeeder::A_EMAIL)->firstOrFail();

        $this->actingAs($user)
            ->withHeaders(['Origin' => 'https://evil.example'])
            ->post(route('logout'))
            ->assertForbidden();

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_foreign_origin_is_refused_on_the_sign_out_posts(): void
    {
        $user = User::where('email', TwoBusinessFixtureSeeder::A_EMAIL)->firstOrFail();

        $this->actingAs($user)
            ->withHeaders(['Origin' => 'https://evil.example'])
            ->post(route('security.sign-out-others'))
            ->assertForbidden();

        $this->actingAs($user)
            ->withHeaders(['Origin' => 'https://evil.example'])
            ->post(route('security.sign-out-everywhere'))
            ->assertForbidden();

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_foreign_origin_is_refused_on_verification_resend(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->withHeaders(['Origin' => 'https://evil.example'])
            ->post(route('verification.send'))
            ->assertForbidden();
    }

    public function test_an_origin_with_the_wrong_scheme_is_refused(): void
    {
        // Scheme is part of the origin (spec Section 8.4): https
        // against the http APP_URL of the test environment refuses
        // even though the host agrees.
        $this->withHeaders(['Origin' => 'https://localhost'])
            ->post(route('login'), [
                'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
                'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
            ])
            ->assertForbidden();
    }

    public function test_an_unparseable_origin_is_refused(): void
    {
        $this->withHeaders(['Origin' => 'http://example.com:abc'])
            ->post(route('login'), [
                'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
                'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
            ])
            ->assertForbidden();
    }
}
