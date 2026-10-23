<?php

namespace Tests\Feature\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\LoginAttemptAlertNotification;
use Database\Seeders\TwoBusinessFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Login defense in depth (spec Section 8.3; review findings H-1, H-2,
 * L-3): canonical throttle keys so format variants share one budget,
 * the per-account aggregate defense across source IPs with an owner
 * alert and a server-verified Turnstile challenge, and a durable audit
 * trail for failures that carries a hash, never the identifier.
 */
class LoginDefenseTest extends TestCase
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

    public function test_email_format_variants_share_one_throttle_budget(): void
    {
        $variants = [
            'owner.a@example.test',
            'owner.a+1@example.test',
            'OWNER.A@EXAMPLE.TEST',
            'owner.a+promo@example.test',
            'Owner.A+2@Example.Test',
        ];

        foreach ($variants as $variant) {
            $this->post(route('login'), [
                'identifier' => $variant,
                'password' => 'wrong-password-xx',
            ])->assertSessionHasErrors('identifier');
        }

        // The budget is spent: even the correct password is throttled,
        // because all five variants canonicalize to one identity.
        $this->post(route('login'), [
            'identifier' => 'owner.a@example.test',
            'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ])->assertSessionHasErrors('identifier');

        $this->assertGuest();
    }

    public function test_phone_format_variants_share_one_throttle_budget(): void
    {
        $variants = [
            '+12125550101',
            '2125550101',
            '(212) 555-0101',
            '1-212-555-0101',
            '+1 212 555 0101',
        ];

        foreach ($variants as $variant) {
            $this->post(route('login'), [
                'identifier' => $variant,
                'password' => 'wrong-password-xx',
            ])->assertSessionHasErrors('identifier');
        }

        $this->post(route('login'), [
            'identifier' => '212-555-0101',
            'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ])->assertSessionHasErrors('identifier');

        $this->assertGuest();
    }

    public function test_aggregate_failures_across_ips_lock_the_account_and_alert_the_owner(): void
    {
        Notification::fake();

        $failuresKey = LoginRequest::accountFailuresKeyFor(TwoBusinessFixtureSeeder::A_EMAIL);

        for ($i = 0; $i < 24; $i++) {
            RateLimiter::hit($failuresKey, 3600);
        }

        // The 25th failure, from yet another address, crosses the line.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post(route('login'), [
                'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
                'password' => 'wrong-password-xx',
            ])->assertSessionHasErrors('identifier');

        Notification::assertSentTo($this->ownerA(), LoginAttemptAlertNotification::class);
        $this->assertSame(1, AuditLog::where('action', 'owner.login_lockout')->count());

        // The account itself is locked: the correct password from a
        // fresh address is refused while the lock runs.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->post(route('login'), [
                'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
                'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
            ])->assertSessionHasErrors('identifier');

        $this->assertGuest();

        // Another account is untouched by the lock.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->post(route('login'), [
                'identifier' => TwoBusinessFixtureSeeder::B_EMAIL,
                'password' => TwoBusinessFixtureSeeder::B_PASSWORD,
            ])->assertRedirect(route('dashboard'));
    }

    public function test_a_verified_turnstile_challenge_opens_a_locked_account(): void
    {
        config([
            'services.turnstile.site_key' => 'test-site-key',
            'services.turnstile.secret_key' => 'test-secret-key',
        ]);

        Http::fake([
            'https://challenges.cloudflare.com/*' => Http::response(['success' => true], 200),
        ]);

        RateLimiter::hit(LoginRequest::accountLockKeyFor(TwoBusinessFixtureSeeder::A_EMAIL), 3600);

        $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
            'cf-turnstile-response' => 'challenge-token',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->ownerA());
    }

    public function test_a_failed_turnstile_challenge_keeps_a_locked_account_locked(): void
    {
        config([
            'services.turnstile.site_key' => 'test-site-key',
            'services.turnstile.secret_key' => 'test-secret-key',
        ]);

        Http::fake([
            'https://challenges.cloudflare.com/*' => Http::response(['success' => false], 200),
        ]);

        RateLimiter::hit(LoginRequest::accountLockKeyFor(TwoBusinessFixtureSeeder::A_EMAIL), 3600);

        $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
            'cf-turnstile-response' => 'bad-token',
        ])->assertSessionHasErrors('identifier');

        $this->assertGuest();
    }

    public function test_failed_logins_are_audited_with_a_hashed_identifier_only(): void
    {
        $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => 'wrong-password-xx',
        ]);

        $row = AuditLog::where('action', 'owner.login_failed')->firstOrFail();

        $this->assertSame(hash('sha256', TwoBusinessFixtureSeeder::A_EMAIL), $row->metadata['identifier_hash']);
        $this->assertStringNotContainsString(TwoBusinessFixtureSeeder::A_EMAIL, json_encode($row->metadata));
        $this->assertSame($this->ownerA()->id, (int) $row->target_id);
        $this->assertSame($this->ownerA()->business_id, (int) $row->business_id);
    }

    public function test_audit_log_rows_are_immutable_through_the_model(): void
    {
        $row = AuditLog::write('owner.login', $this->ownerA());

        $this->expectException(\LogicException::class);

        $row->update(['action' => 'tampered']);
    }
}
