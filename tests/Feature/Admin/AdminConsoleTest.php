<?php

namespace Tests\Feature\Admin;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Referral;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\CanonicalEmail;
use App\Support\Totp;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * The platform console (spec Section 15, ADM-01, ADM-04, XT-49,
 * RDM-28): a separate guard, mandatory 2FA as a binding gate,
 * audited reads and actions, and a suspension lever that stops
 * enrollment while letting pending visits finish.
 */
class AdminConsoleTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;
    private const ADMIN_PASSWORD = 'AdminPassword01!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        $this->app->instance(PhoneVerificationGateway::class, new FakePhoneVerificationGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        Mail::fake();
    }

    private static int $adminSequence = 0;

    private function admin(bool $withTwoFactor = false): User
    {
        self::$adminSequence++;
        $n = self::$adminSequence;

        $admin = User::create([
            'business_id' => null,
            'role' => 'platform_admin',
            'name' => 'Platform Admin',
            'email' => "admin{$n}@example.test",
            'canonical_email' => CanonicalEmail::of("admin{$n}@example.test"),
            'phone_e164' => '+121255509'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'password' => Hash::make(self::ADMIN_PASSWORD),
            'email_verified_at' => now(),
        ]);

        if ($withTwoFactor) {
            $service = app(TwoFactorService::class);
            $service->startEnrollment($admin, self::ADMIN_PASSWORD);
            $admin = $admin->fresh();
            $service->confirmEnrollment($admin, Totp::code((string) $admin->two_factor_secret), self::ADMIN_PASSWORD);
            $admin = $admin->fresh();
        }

        return $admin;
    }

    #[Group('slow')]
    public function test_adm01_access_matrix(): void
    {
        World::business();

        // Anonymous: to the admin sign-in, never the owner one.
        $this->get(route('admin.home'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.businesses'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.audit'))->assertRedirect(route('admin.login'));

        // An owner session is not an admin session (XT-49).
        $business = World::business();
        $owner = World::owner($business);
        $this->actingAs($owner)->get(route('admin.home'))->assertRedirect(route('admin.login'));

        // An admin without confirmed 2FA reaches only setup.
        $noTwoFactor = $this->admin();
        $this->actingAs($noTwoFactor, 'admin')->get(route('admin.home'))
            ->assertRedirect(route('admin.two-factor.setup'));
        $this->actingAs($noTwoFactor, 'admin')->get(route('admin.businesses'))
            ->assertRedirect(route('admin.two-factor.setup'));
        $this->actingAs($noTwoFactor, 'admin')->get(route('admin.two-factor.setup'))->assertOk();

        // An admin with 2FA but no passed challenge reaches only
        // the challenge.
        $withTwoFactor = $this->admin(true);
        $this->actingAs($withTwoFactor, 'admin')->get(route('admin.home'))
            ->assertRedirect(route('admin.two-factor.challenge'));
        $this->actingAs($withTwoFactor, 'admin')->get(route('admin.two-factor.challenge'))->assertOk();

        // With the challenge passed in session, the console opens.
        $this->actingAs($withTwoFactor, 'admin')
            ->withSession(['admin_auth.two_factor_passed' => true])
            ->get(route('admin.home'))
            ->assertOk()
            ->assertSee('Platform console');
    }

    public function test_admin_sign_in_rejects_owner_credentials_generically_and_accepts_admin(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $admin = $this->admin(true);

        $this->post(route('admin.login.store'), [
            'email' => $owner->email,
            'password' => 'WorldPassword01!',
        ])->assertSessionHasErrors('email');

        $this->post(route('admin.login.store'), [
            'email' => $admin->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->post(route('admin.login.store'), [
            'email' => $admin->email,
            'password' => self::ADMIN_PASSWORD,
        ])->assertRedirect(route('admin.home'));

        $this->assertTrue(AuditLog::query()->where('action', 'admin.login_failed')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'admin.login')->exists());
    }

    public function test_full_two_factor_challenge_flow_reaches_the_console(): void
    {
        $admin = $this->admin(true);

        $this->post(route('admin.login.store'), [
            'email' => $admin->email,
            'password' => self::ADMIN_PASSWORD,
        ]);

        $this->get(route('admin.home'))->assertRedirect(route('admin.two-factor.challenge'));

        $this->post(route('admin.two-factor.challenge.store'), [
            'code' => '000000',
        ])->assertSessionHasErrors('code');
        $this->get(route('admin.home'))->assertRedirect(route('admin.two-factor.challenge'));

        $this->post(route('admin.two-factor.challenge.store'), [
            'code' => Totp::code((string) $admin->fresh()->two_factor_secret, time() + \App\Support\Totp::PERIOD_SECONDS),
        ])->assertRedirect(route('admin.home'));

        $this->get(route('admin.home'))->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'admin.two_factor_challenge_passed')->exists());
    }

    public function test_console_reads_and_actions_are_audited(): void
    {
        $admin = $this->admin(true);
        $business = World::business();

        $this->actingAs($admin, 'admin')
            ->withSession(['admin_auth.two_factor_passed' => true])
            ->get(route('admin.businesses.show', $business))
            ->assertOk()
            ->assertSee($business->name);

        $this->assertTrue(AuditLog::query()
            ->where('action', 'admin.console_view')
            ->where('user_id', $admin->id)
            ->where('business_id', $business->id)
            ->exists());
    }

    public function test_business_search_finds_by_name_and_owner_email(): void
    {
        $admin = $this->admin(true);
        $business = World::business();
        $owner = World::owner($business);
        World::business();

        $session = ['admin_auth.two_factor_passed' => true];

        $this->actingAs($admin, 'admin')->withSession($session)
            ->get(route('admin.businesses', ['q' => $business->name]))
            ->assertSee($business->name);

        $response = $this->actingAs($admin, 'admin')->withSession($session)
            ->get(route('admin.businesses', ['q' => $owner->email]));
        $response->assertSee($business->name);
    }

    public function test_business_search_is_literal_and_never_matches_everything(): void
    {
        // Spec verifier findings 4 and 5: a digit-less query
        // must not degenerate into a match-all phone clause, and
        // wildcards are literal text (XT-46).
        $admin = $this->admin(true);
        $first = World::business();
        $second = World::business();
        $session = ['admin_auth.two_factor_passed' => true];

        $this->actingAs($admin, 'admin')->withSession($session)
            ->get(route('admin.businesses', ['q' => 'zzzz-no-such-business']))
            ->assertDontSee($first->name)
            ->assertDontSee($second->name)
            ->assertSee('No businesses match that search.');

        $this->actingAs($admin, 'admin')->withSession($session)
            ->get(route('admin.businesses', ['q' => '%']))
            ->assertDontSee($first->name)
            ->assertDontSee($second->name);
    }

    public function test_admin_credentials_are_refused_at_the_owner_sign_in(): void
    {
        // Security verifier F-03: the guard separation runs in
        // both directions. An admin's correct credentials fail
        // the owner sign-in generically and open no session.
        $admin = $this->admin(true);

        $this->post(route('login'), [
            'identifier' => $admin->email,
            'password' => self::ADMIN_PASSWORD,
        ])->assertSessionHasErrors('identifier');

        $this->assertGuest();
    }

    public function test_admin_login_accepts_a_case_variant_of_the_admin_email(): void
    {
        // Security verifier note N-02: canonical lookup, like
        // the owner sign-in.
        $admin = $this->admin();

        $this->post(route('admin.login.store'), [
            'email' => strtoupper($admin->email),
            'password' => self::ADMIN_PASSWORD,
        ])->assertRedirect(route('admin.home'));
    }

    #[Group('slow')]
    public function test_throttled_admin_login_attempts_are_audited(): void
    {
        // Security verifier F-08: attempts refused by the
        // lockout itself still write the failure audit row.
        $admin = $this->admin();

        for ($i = 0; $i < 7; $i++) {
            $this->post(route('admin.login.store'), [
                'email' => $admin->email,
                'password' => 'wrong-password',
            ]);
        }

        $this->assertSame(7, AuditLog::query()->where('action', 'admin.login_failed')->count());
    }

    public function test_admin_two_factor_confirm_is_throttled_and_failures_are_audited(): void
    {
        // Security verifier F-07: the enrollment confirm gets
        // the same throttle as the challenge, and its failures
        // are security events in the audit log.
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post(route('admin.two-factor.start'), [
            'current_password' => self::ADMIN_PASSWORD,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($admin, 'admin')->post(route('admin.two-factor.confirm'), [
                'current_password' => self::ADMIN_PASSWORD,
                'code' => '000000',
            ])->assertSessionHasErrors('code');
        }

        $this->assertSame(5, AuditLog::query()->where('action', 'admin.two_factor_enrollment_failed')->count());

        $this->actingAs($admin, 'admin')->post(route('admin.two-factor.confirm'), [
            'current_password' => self::ADMIN_PASSWORD,
            'code' => '000000',
        ])->assertStatus(429);
    }

    public function test_adm04_suspension_lever_and_rdm28_pending_visit_still_finishes(): void
    {
        $admin = $this->admin(true);
        $business = World::business();
        [, $qrPlain] = World::qr($business);
        [$membership] = World::membership($business, World::customer('+12125550911', 'Ana', 'Ray'));
        [$referral, $redeemPlain] = World::referral($membership, World::customer('+12125550912', 'Fay', 'One'));

        $session = ['admin_auth.two_factor_passed' => true];

        // The owner has no suspension capability at all.
        $owner = World::owner($business);
        $this->actingAs($owner)->post(route('admin.businesses.suspend', $business), ['reason' => 'x'])
            ->assertRedirect(route('admin.login'));
        $this->assertSame('active', $business->fresh()->status);

        $this->actingAs($admin, 'admin')->withSession($session)
            ->post(route('admin.businesses.suspend', $business), ['reason' => 'Fraud review'])
            ->assertSessionHas('status', 'Business paused.');

        $this->assertSame('disabled', $business->fresh()->status);
        $suspension = AuditLog::query()->where('action', 'admin.business_suspended')->firstOrFail();
        $this->assertSame('Fraud review', $suspension->metadata['reason'] ?? null);
        $this->assertSame($admin->id, $suspension->user_id);

        // Enrollment stops: the QR landing is the generic page.
        $this->get(route('refer.landing', ['token' => $qrPlain]))->assertNotFound();

        // RDM-28: the already-pending visit still completes.
        $page = $this->get(route('redeem.show', ['token' => $redeemPlain]))->assertOk();
        preg_match('/name="nonce" value="([^"]+)"/', $page->getContent(), $m);
        $this->post(route('redeem.confirm', ['token' => $redeemPlain]), ['nonce' => $m[1]]);
        $this->assertSame(Referral::STATE_REDEEMED, $referral->fresh()->state);

        // Reinstatement restores the business.
        $this->actingAs($admin, 'admin')->withSession($session)
            ->post(route('admin.businesses.reinstate', $business), ['reason' => 'Cleared'])
            ->assertSessionHas('status', 'Business reinstated.');
        $this->assertSame('active', $business->fresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'admin.business_reinstated')->exists());
    }

    public function test_suspension_requires_a_reason_and_cannot_touch_a_closed_business(): void
    {
        $admin = $this->admin(true);
        $business = World::business();
        $business->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        $session = ['admin_auth.two_factor_passed' => true];

        $this->actingAs($admin, 'admin')->withSession($session)
            ->post(route('admin.businesses.suspend', $business), ['reason' => 'Fraud review'])
            ->assertSessionHasErrors('suspend');
        $this->assertSame('closed', $business->fresh()->status);

        $active = World::business();
        $this->actingAs($admin, 'admin')->withSession($session)
            ->post(route('admin.businesses.suspend', $active), [])
            ->assertSessionHasErrors('reason');
    }

    public function test_admin_session_cannot_use_the_owner_area(): void
    {
        $admin = $this->admin(true);

        // A real sign-in writes only the admin guard's session
        // key; carrying that cookie to the owner area must not
        // authenticate the web guard (separate guards, separate
        // keys). actingAs() is not used here: it would switch the
        // framework's default guard, which no browser can do.
        $login = $this->post(route('admin.login.store'), [
            'email' => $admin->email,
            'password' => self::ADMIN_PASSWORD,
        ]);
        $this->carryCookiesFrom($login);

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('customers.index'))->assertRedirect(route('login'));
    }

    public function test_risk_feed_and_holds_and_audit_pages_render(): void
    {
        $admin = $this->admin(true);
        $business = World::business();
        [$membership] = World::membership($business, World::customer('+12125550921', 'Ana', 'Ray'));
        [$held] = World::referral($membership, World::customer('+12125550922', 'Fay', 'One'), [
            'hold' => true, 'hold_rules' => ['velocity_daily'], 'held_at' => now(),
        ]);
        \App\Models\ReferralEvent::record('flag_raised', 'system', $business, $held, null, $membership, ['rules' => ['velocity_daily']]);

        $session = ['admin_auth.two_factor_passed' => true];

        $this->actingAs($admin, 'admin')->withSession($session)
            ->get(route('admin.risk-feed'))
            ->assertOk()
            ->assertSee($business->name);
        $this->actingAs($admin, 'admin')->withSession($session)
            ->get(route('admin.holds'))
            ->assertOk()
            ->assertSee('Read only');
        $this->actingAs($admin, 'admin')->withSession($session)
            ->get(route('admin.audit'))
            ->assertOk()
            ->assertSee('Audit log');
    }
}
