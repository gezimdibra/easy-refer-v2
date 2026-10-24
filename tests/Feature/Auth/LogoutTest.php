<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\TwoBusinessFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Protocol Section 5.2: logout destroys the server-side session, and a
 * replayed old session gets nothing. The database session driver is the
 * production driver, so these tests run against it directly.
 */
class LogoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);

        $this->seed(TwoBusinessFixtureSeeder::class);
    }

    #[Group('slow')]
    public function test_logout_is_post_only(): void
    {
        $user = User::query()->where('email', TwoBusinessFixtureSeeder::A_EMAIL)->first();

        $this->actingAs($user)->get(route('logout'))->assertMethodNotAllowed();
    }

    public function test_logout_destroys_the_server_side_session(): void
    {
        $login = $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ]);
        $login->assertRedirect(route('dashboard'));

        $sessionId = session()->getId();
        $this->assertTrue(DB::table('sessions')->where('id', $sessionId)->exists());

        $this->carryCookiesFrom($login);
        $this->post(route('logout'))->assertRedirect(route('home'));
        $this->assertGuest();

        $this->assertFalse(DB::table('sessions')->where('id', $sessionId)->exists());

        // Replaying the pre-logout session cookie, exactly as a browser
        // holding it would, must not resurrect anything.
        $this->carryCookiesFrom($login);
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }
}
