<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\TwoBusinessFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Protocol Section 5.3, Phase 1 surface: the route inventory proves the
 * single guard covers every protected /auth route (the audit H4
 * failure), and each protected page is exercised as its owner, as the
 * other fixture business, and anonymous.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $ownerA;

    private User $ownerB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TwoBusinessFixtureSeeder::class);

        $this->ownerA = User::query()->where('email', TwoBusinessFixtureSeeder::A_EMAIL)->firstOrFail();
        $this->ownerB = User::query()->where('email', TwoBusinessFixtureSeeder::B_EMAIL)->firstOrFail();
    }

    public function test_route_inventory_every_protected_auth_route_carries_the_guard(): void
    {
        $publicNames = [
            'login',
            'login.store',
            'register',
            'register.store',
            'password.request',
            'password.email',
            'password.reset',
            'password.store',
            'account.unlock.show',
            'account.unlock',
        ];

        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'auth/')) {
                continue;
            }

            if (in_array($route->getName(), $publicNames, true)) {
                continue;
            }

            $checked++;
            $this->assertContains(
                'auth',
                $route->gatherMiddleware(),
                'Route '.$route->uri().' ('.$route->getName().') is missing the auth guard'
            );
        }

        $this->assertGreaterThan(0, $checked, 'The inventory must actually examine protected routes');
    }

    public function test_logout_has_no_get_route(): void
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === 'auth/logout') {
                $this->assertNotContains('GET', $route->methods());
            }
        }
    }

    public function test_anonymous_visitors_are_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('security'))->assertRedirect(route('login'));
        $this->get(route('verification.notice'))->assertRedirect(route('login'));
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->post(route('verification.send'))->assertRedirect(route('login'));
        $this->post(route('security.sign-out-others'))->assertRedirect(route('login'));
        $this->put(route('security.password'), [])->assertRedirect(route('login'));
    }

    public function test_each_owner_sees_only_their_own_business(): void
    {
        $this->actingAs($this->ownerA)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Alpha Bakery')
            ->assertSee(TwoBusinessFixtureSeeder::A_EMAIL)
            ->assertDontSee('Beta Barbers')
            ->assertDontSee(TwoBusinessFixtureSeeder::B_EMAIL);

        $this->actingAs($this->ownerB)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Beta Barbers')
            ->assertSee(TwoBusinessFixtureSeeder::B_EMAIL)
            ->assertDontSee('Alpha Bakery')
            ->assertDontSee(TwoBusinessFixtureSeeder::A_EMAIL);
    }

    public function test_security_page_is_reachable_by_each_owner_for_their_own_account(): void
    {
        $this->actingAs($this->ownerA)->get(route('security'))->assertOk();
        $this->actingAs($this->ownerB)->get(route('security'))->assertOk();
    }
}
