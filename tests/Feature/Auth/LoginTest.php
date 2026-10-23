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
