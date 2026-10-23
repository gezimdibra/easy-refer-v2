<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Session lifecycle completions (spec Section 8.4; review finding
 * M-2): the ID rotates periodically during activity, and the lifetime
 * middleware guards the whole authenticated group, not a subgroup.
 */
class SessionLifecycleFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_session_id_rotates_after_thirty_minutes_of_activity(): void
    {
        $user = User::factory()->create();
        $rotatedAt = now()->subMinutes(31)->timestamp;

        $this->actingAs($user);
        $this->withSession([
            'auth.login_at' => now()->subHours(2)->timestamp,
            'auth.rotated_at' => $rotatedAt,
        ]);

        $this->get(route('dashboard'))->assertOk();

        $this->assertGreaterThan($rotatedAt, session('auth.rotated_at'));
    }

    public function test_a_missing_rotation_marker_is_stamped_from_the_login_time(): void
    {
        $user = User::factory()->create();
        $loginAt = now()->subHours(2)->timestamp;

        $this->actingAs($user);
        $this->withSession(['auth.login_at' => $loginAt]);

        $this->get(route('dashboard'))->assertOk();

        $this->assertSame($loginAt, session('auth.rotated_at'));
    }

    public function test_the_absolute_cap_also_guards_the_verification_routes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        $this->withSession(['auth.login_at' => now()->subHours(25)->timestamp]);

        $this->get(route('verification.notice'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
