<?php

namespace Tests\Feature\Auth;

use App\Models\RememberToken;
use App\Models\User;
use App\Services\Auth\RememberMeService;
use Database\Seeders\TwoBusinessFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Selector/validator remember-me, HTTP surface (spec Section 8.4;
 * review findings H-3 and H-4): issuance at login, hashed storage,
 * and revocation on logout, sign-out-others, and password reset. The
 * consume/rotation/theft semantics are covered directly in
 * RememberMeServiceTest and RememberMeMiddlewareTest, because the
 * test harness shares one session store across requests and cannot
 * simulate a second browser faithfully.
 */
class RememberMeTest extends TestCase
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

    private function rememberCookieValue(TestResponse $response): string
    {
        $value = null;

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === RememberMeService::COOKIE_NAME) {
                // A response can carry more than one cookie of this name
                // (the test cookie jar re-attaches queued cookies); a
                // browser honors the last one.
                $value = (string) $cookie->getValue();
            }
        }

        return $value ?? $this->fail('The response did not set a remember cookie.');
    }

    private function loginWithRemember(): TestResponse
    {
        return $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
            'remember' => '1',
        ]);
    }

    public function test_login_with_remember_issues_a_selector_validator_token(): void
    {
        $response = $this->loginWithRemember()->assertRedirect(route('dashboard'));

        $row = RememberToken::firstOrFail();
        $decrypted = Crypt::decrypt($this->rememberCookieValue($response), false);
        $plain = str_contains($decrypted, '|') ? explode('|', $decrypted, 2)[1] : $decrypted;
        [$selector, $validator] = explode('.', $plain, 2);

        $this->assertSame($row->selector, $selector);
        $this->assertSame(hash('sha256', $validator), $row->validator_hash);
        $this->assertStringNotContainsString($validator, $row->validator_hash);
        $this->assertFalse($row->isExpired());
        $this->assertSame($this->ownerA()->id, $row->user->id);
    }

    public function test_login_without_remember_issues_no_token(): void
    {
        $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ])->assertRedirect(route('dashboard'));

        $this->assertSame(0, RememberToken::count());
    }

    public function test_logout_revokes_the_presented_remember_token(): void
    {
        $login = $this->loginWithRemember();
        $this->assertSame(1, RememberToken::count());

        $this->carryCookiesFrom($login);
        $this->post(route('logout'))->assertRedirect(route('home'));

        $this->assertSame(0, RememberToken::count());
    }

    public function test_sign_out_others_revokes_remember_tokens(): void
    {
        $user = $this->ownerA();
        app(RememberMeService::class)->issue($user);
        $this->assertSame(1, RememberToken::count());

        $this->actingAs($user)
            ->post(route('security.sign-out-others'))
            ->assertRedirect();

        $this->assertSame(0, RememberToken::count());
    }

    public function test_password_reset_revokes_remember_tokens(): void
    {
        $user = $this->ownerA();
        app(RememberMeService::class)->issue($user);

        $token = Password::createToken($user);

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a fresh long password 99',
            'password_confirmation' => 'a fresh long password 99',
        ])->assertRedirect(route('login'));

        $this->assertSame(0, RememberToken::count());
    }
}
