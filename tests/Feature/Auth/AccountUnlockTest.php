<?php

namespace Tests\Feature\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\AccountUnlockNotification;
use App\Notifications\LoginAttemptAlertNotification;
use App\Services\Auth\AccountUnlockService;
use Database\Seeders\TwoBusinessFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The emailed one-time unlock link for the per-account aggregate
 * login lock (spec Section 8.3, Amendment 5; security finding M-1).
 * The lock binds the account by identity alone; without a fallback,
 * a distributed spray could keep the legitimate owner out whenever
 * Turnstile keys are not configured. The link is the fallback: it is
 * issued when the lock trips, works once, expires with the lock
 * window, and clears the aggregate lock and nothing else.
 */
class AccountUnlockTest extends TestCase
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

    /**
     * Drive the aggregate defense to its crossing from many source
     * addresses, the distributed spray the defense exists for, and
     * return the unlock token from the email it produced.
     */
    private function sprayUntilLocked(): string
    {
        Notification::fake();

        $failuresKey = LoginRequest::accountFailuresKeyFor(TwoBusinessFixtureSeeder::A_EMAIL);

        for ($i = 0; $i < 24; $i++) {
            RateLimiter::hit($failuresKey, 3600);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post(route('login'), [
                'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
                'password' => 'wrong-password-xx',
            ])->assertSessionHasErrors('identifier');

        Notification::assertSentTo($this->ownerA(), LoginAttemptAlertNotification::class);

        $token = null;

        Notification::assertSentTo(
            $this->ownerA(),
            AccountUnlockNotification::class,
            function (AccountUnlockNotification $notification) use (&$token) {
                $token = $notification->token;

                return true;
            }
        );

        $this->assertNotNull($token);

        return $token;
    }

    public function test_a_spray_trips_the_lock_and_the_emailed_link_unlocks_it(): void
    {
        $token = $this->sprayUntilLocked();

        // The lock binds: the correct password is refused.
        $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ])->assertSessionHasErrors('identifier');
        $this->assertGuest();

        // The landing page renders and the POST consumes the token.
        $this->get(route('account.unlock.show', ['token' => $token]))->assertOk();

        $this->post(route('account.unlock'), ['token' => $token])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Your account has been unlocked. You can sign in now.');

        // Sign-in works again, and the unlock is audited.
        $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ])->assertRedirect(route('dashboard'));

        $this->assertSame(1, AuditLog::where('action', 'owner.account_unlocked')->count());
    }

    public function test_an_unlock_token_cannot_be_used_twice(): void
    {
        $token = $this->sprayUntilLocked();

        $this->post(route('account.unlock'), ['token' => $token])
            ->assertRedirect(route('login'));

        // A second lock is now in force; the spent token must not
        // clear it.
        RateLimiter::hit(LoginRequest::accountLockKeyFor(TwoBusinessFixtureSeeder::A_EMAIL), 3600);

        $this->post(route('account.unlock'), ['token' => $token])
            ->assertSessionHasErrors('token');

        $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ])->assertSessionHasErrors('identifier');
        $this->assertGuest();
    }

    public function test_an_expired_unlock_token_is_rejected(): void
    {
        $token = app(AccountUnlockService::class)->issue($this->ownerA(), TwoBusinessFixtureSeeder::A_EMAIL);

        $this->travel((int) config('security.account_unlock.token_ttl_seconds') + 60)->seconds();

        RateLimiter::hit(LoginRequest::accountLockKeyFor(TwoBusinessFixtureSeeder::A_EMAIL), 3600);

        $this->post(route('account.unlock'), ['token' => $token])
            ->assertSessionHasErrors('token');

        $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ])->assertSessionHasErrors('identifier');
        $this->assertGuest();
    }

    public function test_a_forged_unlock_token_is_rejected_with_the_same_generic_error(): void
    {
        $this->post(route('account.unlock'), ['token' => 'not-a-real-token'])
            ->assertSessionHasErrors('token');

        $this->assertSame(0, AuditLog::where('action', 'owner.account_unlocked')->count());
    }

    public function test_unlocking_does_not_clear_the_per_ip_throttle_for_other_identifiers(): void
    {
        $token = app(AccountUnlockService::class)->issue($this->ownerA(), TwoBusinessFixtureSeeder::A_EMAIL);

        // Spend owner B's per-IP budget from this address.
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login'), [
                'identifier' => TwoBusinessFixtureSeeder::B_EMAIL,
                'password' => 'wrong-password-xx',
            ]);
        }

        $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::B_EMAIL,
            'password' => TwoBusinessFixtureSeeder::B_PASSWORD,
        ])->assertSessionHasErrors('identifier');

        $this->post(route('account.unlock'), ['token' => $token])
            ->assertRedirect(route('login'));

        // B's per-IP throttle state survived A's unlock untouched.
        $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::B_EMAIL,
            'password' => TwoBusinessFixtureSeeder::B_PASSWORD,
        ])->assertSessionHasErrors('identifier');
        $this->assertGuest();
    }

    public function test_only_the_token_hash_is_stored(): void
    {
        $token = app(AccountUnlockService::class)->issue($this->ownerA(), TwoBusinessFixtureSeeder::A_EMAIL);

        $keys = Cache::get('account-unlock:user:'.hash('sha256', TwoBusinessFixtureSeeder::A_EMAIL));

        $this->assertSame(hash('sha256', $token), $keys);
        $this->assertNotSame($token, $keys);
    }

    public function test_a_new_unlock_token_supersedes_the_previous_one(): void
    {
        $service = app(AccountUnlockService::class);

        $first = $service->issue($this->ownerA(), TwoBusinessFixtureSeeder::A_EMAIL);
        $second = $service->issue($this->ownerA(), TwoBusinessFixtureSeeder::A_EMAIL);

        $this->assertNull($service->consume($first));
        $this->assertNotNull($service->consume($second));
    }

    public function test_the_unlock_email_carries_the_link_on_the_configured_mount(): void
    {
        $mail = (new AccountUnlockNotification('plain-token-123'))->toMail($this->ownerA());

        $this->assertSame('Unlock your Easy Refer account', $mail->subject);
        $this->assertStringContainsString('/auth/unlock/plain-token-123', $mail->actionUrl);
    }
}
