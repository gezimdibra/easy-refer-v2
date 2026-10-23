<?php

namespace Tests\Feature\Auth;

use App\Contracts\SquareCustomerGateway;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\User;
use Database\Seeders\TwoBusinessFixtureSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Fakes\FakeSquareCustomerGateway;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const BREACHED_PASSWORD = 'breached-password-99';

    protected function setUp(): void
    {
        parent::setUp();

        // Breach screening runs against an empty corpus, except for the
        // one password the breached-password test uses: its hash prefix
        // answers with the matching suffix line, like the real corpus.
        Http::fake(function ($request) {
            $breachedSha1 = strtoupper(sha1(self::BREACHED_PASSWORD));

            if (str_contains($request->url(), '/range/'.substr($breachedSha1, 0, 5))) {
                return Http::response(substr($breachedSha1, 5).':386', 200);
            }

            return Http::response('', 200);
        });
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Corner Deli',
            'name' => 'Dana Owner',
            'email' => 'dana@example.test',
            'phone' => '(212) 555-0147',
            'password' => 'a long phrase nobody else uses 42',
            'password_confirmation' => 'a long phrase nobody else uses 42',
            'country' => 'US',
            'street' => '1 Main Street',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
        ], $overrides);
    }

    public function test_register_page_renders_the_four_wizard_steps(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('Step 1: Business information')
            ->assertSee('Step 2: Contact details')
            ->assertSee('Step 3: Create password')
            ->assertSee('Step 4: Location');
    }

    public function test_successful_registration_creates_business_and_owner(): void
    {
        Notification::fake();

        $response = $this->post(route('register'), $this->payload());

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $business = Business::query()->where('name', 'Corner Deli')->first();
        $this->assertNotNull($business);
        $this->assertSame('dana@example.test', $business->contact_email);
        $this->assertSame('+12125550147', $business->contact_phone_e164);
        $this->assertNull($business->square_customer_id, 'The Phase 1 null gateway stores no Square customer');

        $user = User::query()->where('email', 'dana@example.test')->first();
        $this->assertNotNull($user);
        $this->assertSame($business->id, $user->business_id);
        $this->assertSame('owner', $user->role);
        $this->assertSame('dana@example.test', $user->canonical_email);
        $this->assertSame('+12125550147', $user->phone_e164);
        $this->assertNull($user->email_verified_at);

        $this->assertSame('argon2id', password_get_info($user->password)['algoName']);

        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertTrue(AuditLog::query()->where('action', 'owner.registered')->where('user_id', $user->id)->exists());
    }

    public function test_square_customer_id_is_stored_when_the_gateway_responds(): void
    {
        $gateway = new FakeSquareCustomerGateway;
        $this->app->instance(SquareCustomerGateway::class, $gateway);

        $this->post(route('register'), $this->payload())->assertRedirect(route('dashboard'));

        $business = Business::query()->where('name', 'Corner Deli')->first();
        $this->assertSame([$business->id], $gateway->createdForBusinessIds);
        $this->assertSame('sq_fake_customer_'.$business->id, $business->square_customer_id);
    }

    public function test_duplicate_email_is_rejected_with_the_generic_message(): void
    {
        $this->seed(TwoBusinessFixtureSeeder::class);

        $response = $this->post(route('register'), $this->payload([
            'email' => TwoBusinessFixtureSeeder::A_EMAIL,
            'phone' => '(718) 555-0199',
        ]));

        $response->assertSessionHasErrors([
            'account' => 'An account with these details already exists. Sign in or reset your password instead.',
        ]);
        $this->assertGuest();
        $this->assertSame(2, Business::count());
    }

    public function test_plus_tagged_duplicate_email_is_also_rejected(): void
    {
        $this->seed(TwoBusinessFixtureSeeder::class);

        $response = $this->post(route('register'), $this->payload([
            'email' => 'Owner.A+promo@example.test',
            'phone' => '(718) 555-0199',
        ]));

        $response->assertSessionHasErrors('account');
        $this->assertGuest();
    }

    public function test_duplicate_phone_is_rejected_with_the_generic_message(): void
    {
        $this->seed(TwoBusinessFixtureSeeder::class);

        $response = $this->post(route('register'), $this->payload([
            'email' => 'fresh@example.test',
            'phone' => '212-555-0101',
        ]));

        $response->assertSessionHasErrors('account');
        $this->assertGuest();
    }

    public function test_short_password_is_rejected(): void
    {
        $response = $this->post(route('register'), $this->payload([
            'password' => 'short',
            'password_confirmation' => 'short',
        ]));

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
        $this->assertSame(0, Business::count());
    }

    public function test_breached_password_is_rejected(): void
    {
        $prefix = substr(strtoupper(sha1(self::BREACHED_PASSWORD)), 0, 5);

        $response = $this->post(route('register'), $this->payload([
            'password' => self::BREACHED_PASSWORD,
            'password_confirmation' => self::BREACHED_PASSWORD,
        ]));

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
        Http::assertSent(fn ($request) => str_contains($request->url(), '/range/'.$prefix));
    }

    public function test_password_confirmation_must_match(): void
    {
        $response = $this->post(route('register'), $this->payload([
            'password_confirmation' => 'a different long phrase 42',
        ]));

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_invalid_phone_is_rejected(): void
    {
        $response = $this->post(route('register'), $this->payload([
            'phone' => '123',
        ]));

        $response->assertSessionHasErrors('phone');
        $this->assertGuest();
    }
}
