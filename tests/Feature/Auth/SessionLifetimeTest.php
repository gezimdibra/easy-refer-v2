<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec Section 8.4 and protocol Section 5.2: the absolute session cap is
 * enforced server-side from the login timestamp, alongside the idle
 * timeout handled by the session lifetime.
 */
class SessionLifetimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_session_older_than_the_absolute_cap_is_ended(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        $this->withSession(['auth.login_at' => now()->subHours(25)->timestamp]);

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_session_inside_the_cap_works_normally(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        $this->withSession(['auth.login_at' => now()->subHours(2)->timestamp]);

        $this->get(route('dashboard'))->assertOk();
        $this->assertAuthenticated();
    }

    public function test_a_session_without_a_marker_gets_one_stamped(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $this->assertNotNull(session('auth.login_at'));
    }
}
