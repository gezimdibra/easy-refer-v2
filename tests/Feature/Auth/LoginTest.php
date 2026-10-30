<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\TwoBusinessFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TwoBusinessFixtureSeeder::class);
    }

    public function test_login_page_renders(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Email or phone number');
    }

    public function test_login_page_uses_a_short_eyebrow_sentence_case_and_the_approved_stat_labels(): void
    {
        // Corrections wave (2026-10-24): CV-01 (sentence case link
        // label), NEW-7 (the brand panel carries the approved stat
        // labels, matching the home band). AU-1 (R5 pack) retired
        // the TY-07 eyebrow: the heading and panel do its job.
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Your one-stop referral platform')
            ->assertDontSee('one-stop platform for managing referrals')
            ->assertSee('Forgot password?')
            ->assertDontSee('Forgot Password?')
            ->assertSee('higher purchase amount')
            ->assertSee('customer retention')
            ->assertDontSee('retention rate');
    }

    public function test_owner_can_login_with_email(): void
    {
        $response = $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $user = User::query()->where('email', TwoBusinessFixtureSeeder::A_EMAIL)->first();
        $this->assertTrue(AuditLog::query()->where('action', 'owner.login')->where('user_id', $user->id)->exists());
    }

    public function test_owner_can_login_with_phone_in_any_format(): void
    {
        foreach (['(212) 555-0101', '+1 212 555 0101', '2125550101'] as $phone) {
            $this->post(route('login'), [
                'identifier' => $phone,
                'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
            ])->assertRedirect(route('dashboard'));

            $this->assertAuthenticated();
            $this->post(route('logout'));
            $this->assertGuest();
        }
    }

    public function test_login_with_plus_tagged_email_matches_the_canonical_account(): void
    {
        $this->post(route('login'), [
            'identifier' => 'Owner.A+news@example.test',
            'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
    }

    public function test_failed_login_keeps_the_forgot_link_directly_under_the_password_field(): void
    {
        // Owner Finding 6 companion pin (2026-10-29): in the
        // FAILED-login state the "Forgot password?" link must stay
        // visible at 390x664 without scrolling. The error summary
        // renders ABOVE the form in the auth layout, so its height
        // (including the listing banner the single-source summary
        // convergence ships) pushes the form down from the top; the
        // link's safety comes from sitting directly under the
        // password field, early in the form. This pin holds the DOM
        // order that the computed-position proof relies on:
        // summary region, identifier field, password field, then
        // the forgot link, with nothing but the remember-me row
        // between the field and the link.
        $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => 'not the password at all',
        ])->assertSessionHasErrors('identifier');

        $html = $this->get(route('login'))->assertOk()->getContent();

        $banner = strpos($html, 'data-error-summary');
        $password = strpos($html, 'name="password"');
        $forgot = strpos($html, 'Forgot password?');
        $submit = strpos($html, '>Sign in</button>');

        $this->assertTrue($banner !== false && $password !== false && $forgot !== false && $submit !== false);
        $this->assertTrue(
            $banner < $password && $password < $forgot && $forgot < $submit,
            'DOM order must be: error summary, password field, forgot link, submit button.'
        );
    }

    public function test_wrong_password_and_unknown_identifier_give_the_identical_error(): void
    {
        $wrongPassword = $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => 'not the password at all',
        ]);
        $wrongPassword->assertSessionHasErrors(['identifier' => 'The email or phone number and password do not match. Please try again.']);

        $unknown = $this->post(route('login'), [
            'identifier' => 'ghost@example.test',
            'password' => 'not the password at all',
        ]);
        $unknown->assertSessionHasErrors(['identifier' => 'The email or phone number and password do not match. Please try again.']);

        $this->assertGuest();
    }

    public function test_an_identifier_that_is_neither_email_nor_phone_gets_the_generic_error(): void
    {
        $this->post(route('login'), [
            'identifier' => 'call-me-maybe',
            'password' => 'whatever password',
        ])->assertSessionHasErrors(['identifier' => 'The email or phone number and password do not match. Please try again.']);

        $this->assertGuest();
    }

    public function test_login_is_throttled_after_five_failures_and_stays_throttled_for_the_correct_password(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->post(route('login'), [
                'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
                'password' => 'wrong password '.$i,
            ])->assertSessionHasErrors(['identifier' => 'The email or phone number and password do not match. Please try again.']);
        }

        $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => 'wrong password final',
        ])->assertSessionHasErrors('identifier');

        $this->assertStringContainsString(
            'Too many attempts. Please wait a few minutes and try again.',
            session('errors')->get('identifier')[0]
        );

        $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ])->assertSessionHasErrors('identifier');

        $this->assertStringContainsString(
            'Too many attempts. Please wait a few minutes and try again.',
            session('errors')->get('identifier')[0]
        );
        $this->assertGuest();
    }

    public function test_session_id_regenerates_on_login(): void
    {
        $this->get(route('login'));
        $before = session()->getId();

        $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ]);

        $this->assertNotSame($before, session()->getId());
        $this->assertNotNull(session('auth.login_at'));
    }
}
