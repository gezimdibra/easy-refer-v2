<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\ResetPasswordNotification as ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Spec Section 4.3 and protocol Section 5.1: the request response never
 * reveals whether the account exists; tokens are single-use, expire at
 * 60 minutes, and a successful reset kills sessions and remember tokens.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC_STATUS = 'If an account exists for that email, a reset link is on its way.';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'https://api.pwnedpasswords.com/*' => Http::response('', 200),
        ]);
    }

    public function test_forgot_password_page_renders(): void
    {
        $this->get(route('password.request'))->assertOk()->assertSee('Reset your password');
    }

    public function test_reset_round_trip(): void
    {
        Notification::fake();
        $user = User::factory()->create(['remember_token' => 'existing-remember-token']);
        DB::table('sessions')->insert([
            'id' => 'stale-session-id',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'x',
            'last_activity' => now()->timestamp,
        ]);

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHas('status', self::GENERIC_STATUS);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });
        $this->assertNotNull($token);

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()
            ->assertSee('Set a new password');

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a brand new long phrase 7',
            'password_confirmation' => 'a brand new long phrase 7',
        ])->assertRedirect(route('login'));

        $user->refresh();
        $this->assertTrue(Hash::check('a brand new long phrase 7', $user->password));
        $this->assertNull($user->remember_token);
        $this->assertFalse(DB::table('sessions')->where('id', 'stale-session-id')->exists());
        Notification::assertSentTo($user, PasswordChangedNotification::class);

        // Single use: the same token cannot reset again.
        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'another long phrase here 8',
            'password_confirmation' => 'another long phrase here 8',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('a brand new long phrase 7', $user->fresh()->password));

        // And the new password signs in.
        $this->post(route('login'), [
            'identifier' => $user->email,
            'password' => 'a brand new long phrase 7',
        ])->assertRedirect(route('dashboard'));
    }

    public function test_request_for_an_unknown_email_answers_identically_and_sends_nothing(): void
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => 'ghost@example.test'])
            ->assertSessionHas('status', self::GENERIC_STATUS);

        Notification::assertNothingSent();
    }

    public function test_expired_token_is_rejected(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->update(['created_at' => now()->subMinutes(61)]);

        $oldPasswordHash = $user->password;

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a brand new long phrase 7',
            'password_confirmation' => 'a brand new long phrase 7',
        ])->assertSessionHasErrors('email');

        $this->assertSame($oldPasswordHash, $user->fresh()->password);
    }

    public function test_weak_password_is_rejected_on_reset(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');
    }
}
