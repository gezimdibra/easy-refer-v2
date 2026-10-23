<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\TwoBusinessFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Password reset completions (review findings M-1, L-1, L-2): the
 * submission endpoint carries its own throttle, the token resolves
 * against the canonical identity rather than the stored string, and
 * the audit trail counts links actually sent.
 */
class PasswordResetFixesTest extends TestCase
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

    public function test_a_reset_submitted_with_a_plus_variant_email_still_matches_the_token(): void
    {
        $user = $this->ownerA();
        $token = Password::createToken($user);

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => 'owner.a+shopping@example.test',
            'password' => 'the replacement password 5',
            'password_confirmation' => 'the replacement password 5',
        ])->assertRedirect(route('login'));

        $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => 'the replacement password 5',
        ])->assertRedirect(route('dashboard'));
    }

    public function test_the_audit_row_is_written_only_when_a_link_is_actually_sent(): void
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => TwoBusinessFixtureSeeder::A_EMAIL]);
        $this->assertSame(1, AuditLog::where('action', 'owner.password_reset_requested')->count());

        // The broker's own resend throttle suppresses this one: the
        // response is identical, but no link went out and no row is
        // written.
        $this->post(route('password.email'), ['email' => TwoBusinessFixtureSeeder::A_EMAIL]);
        $this->assertSame(1, AuditLog::where('action', 'owner.password_reset_requested')->count());
    }

    public function test_a_reset_request_for_an_unknown_email_writes_no_audit_row(): void
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => 'ghost@example.test'])
            ->assertSessionHas('status');

        $this->assertSame(0, AuditLog::where('action', 'owner.password_reset_requested')->count());
    }

    public function test_the_reset_submission_endpoint_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('password.store'), [
                'token' => 'bogus-token',
                'email' => TwoBusinessFixtureSeeder::A_EMAIL,
                'password' => 'some long password 1',
                'password_confirmation' => 'some long password 1',
            ])->assertRedirect();
        }

        $this->post(route('password.store'), [
            'token' => 'bogus-token',
            'email' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => 'some long password 1',
            'password_confirmation' => 'some long password 1',
        ])->assertStatus(429);
    }
}
