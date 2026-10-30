<?php

namespace Tests\Feature\Auth;

use App\Contracts\SquareCustomerGateway;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\User;
use Database\Seeders\TwoBusinessFixtureSeeder;
use App\Notifications\VerifyEmailNotification as VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Fakes\FakeSquareCustomerGateway;
use Tests\Fakes\ThrowingSquareCustomerGateway;
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
        // RS2-2: the checkpoint payload is Steps 1-3 only. The
        // address is no longer a signup field; it is collected
        // by the onboarding Location step.
        return array_merge([
            'business_name' => 'Corner Deli',
            'name' => 'Dana Owner',
            'email' => 'dana@example.test',
            'phone' => '(212) 555-0147',
            'password' => 'a long phrase nobody else uses 42',
            'password_confirmation' => 'a long phrase nobody else uses 42',
        ], $overrides);
    }

    public function test_register_page_renders_the_three_wizard_steps(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('Step 1: Business Information')
            ->assertSee('Step 2: Contact Details')
            ->assertSee('Step 3: Create Password')
            ->assertDontSee('Step 4: Location')
            ->assertSee('Three short steps to your account. You add your address next, while you set up.');
    }

    public function test_successful_registration_creates_business_and_owner(): void
    {
        Notification::fake();

        $response = $this->post(route('register'), $this->payload());

        $response->assertRedirect(route('onboarding.welcome'));
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

    public function test_no_square_customer_is_created_at_the_checkpoint(): void
    {
        // RS2-2: Square provisioning is deferred past the
        // checkpoint (to the card moment or the Location save),
        // so a half-finished signup has NO Square object at all.
        $gateway = new FakeSquareCustomerGateway;
        $this->app->instance(SquareCustomerGateway::class, $gateway);

        $this->post(route('register'), $this->payload())->assertRedirect(route('onboarding.welcome'));

        $business = Business::query()->where('name', 'Corner Deli')->first();
        $this->assertSame([], $gateway->createdForBusinessIds);
        $this->assertNull($business->square_customer_id);
    }

    public function test_signup_completes_when_the_square_gateway_throws(): void
    {
        // RS2-2 form of AUTH-37: the checkpoint never calls the
        // gateway, so even a throwing gateway cannot fail the
        // signup; the business sits in the pending provisioning
        // state (square_customer_id null) the card moment and
        // the Location save retry. The SquareGatewayTest covers
        // the API-error leg through the real SDK gateway.
        $this->app->instance(SquareCustomerGateway::class, new ThrowingSquareCustomerGateway);

        $this->post(route('register'), $this->payload())->assertRedirect(route('onboarding.welcome'));

        $business = Business::query()->where('name', 'Corner Deli')->firstOrFail();
        $this->assertNull($business->square_customer_id);
        $this->assertNotNull($business->owner);
        $this->assertDatabaseHas('qr_codes', ['business_id' => $business->id]);
    }

    public function test_duplicate_email_is_rejected_with_the_generic_message(): void
    {
        $this->seed(TwoBusinessFixtureSeeder::class);

        $response = $this->post(route('register'), $this->payload([
            'email' => TwoBusinessFixtureSeeder::A_EMAIL,
            'phone' => '(718) 555-0199',
        ]));

        $response->assertSessionHasErrors([
            'account' => 'An account with these details already exists. Sign in or reset your password.',
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
