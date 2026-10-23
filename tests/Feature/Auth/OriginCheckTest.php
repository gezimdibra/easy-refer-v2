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
}
