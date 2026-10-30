<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Contracts\SquareCustomerGateway;
use App\Contracts\SquarePaymentsGateway;
use App\Models\User;
use App\Support\CanonicalEmail;
use Database\Seeders\TwoBusinessFixtureSeeder;
use App\Notifications\ResetPasswordNotification as ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Fakes\FakeSquareCustomerGateway;
use Tests\Fakes\FakeSquarePaymentsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * EMPTY ERROR SUMMARY class fix (2026-10-29): the standing rule is
 * that an error summary is a single-source component — the header
 * count and the message list derive from the same error bag in the
 * same block, so a header can never render without its messages.
 * Every pin in this file submits invalid data to a real form and
 * asserts each message's TEXT renders INSIDE the summary region
 * (asserting refusal, or field-level presence alone, is NOT
 * sufficient — that is the generalized r36 lesson).
 */
class ErrorSummaryClassTest extends TestCase
{
    use RefreshDatabase;

    private FakeSquarePaymentsGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PhoneVerificationGateway::class, new FakePhoneVerificationGateway);
        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        $this->gateway = new FakeSquarePaymentsGateway;
        $this->app->instance(SquarePaymentsGateway::class, $this->gateway);
        $this->app->instance(SquareCustomerGateway::class, new FakeSquareCustomerGateway);
        Mail::fake();
        // Password breach screening consults the range API in
        // other suites; answer it empty here (RegistrationTest's
        // standing fake).
        Http::fake(fn () => Http::response('', 200));
    }

    private function carryFlowCookie(\Illuminate\Testing\TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'er2_flow') {
                $this->withUnencryptedCookie('er2_flow', $cookie->getValue());
            }
        }
    }

    /**
     * The summary region: from the summary marker to the close of
     * its banner div (the banner contains no nested divs).
     */
    private function summaryRegion(string $html): string
    {
        $this->assertMatchesRegularExpression('/data-error-summary>/', $html, 'no error summary rendered at all');

        preg_match('/data-error-summary>(.*?)<\/div>/s', $html, $m);

        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    private function assertMessageInSummary(string $html, string $message): void
    {
        $this->assertStringContainsString(
            $message,
            $this->summaryRegion($html),
            'message text did not render inside the error summary region: '.$message
        );
    }

    public function test_login_failed_submit_names_the_problem_inside_the_summary(): void
    {
        $this->seed(TwoBusinessFixtureSeeder::class);

        $this->post(route('login.store'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => 'not the password at all',
        ])->assertSessionHasErrors(['identifier']);

        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertMessageInSummary($html, 'One thing to fix:');
        $this->assertMessageInSummary($html, 'The email or phone number and password do not match. Please try again.');

        // The summary anchor resolves to a real field id.
        $this->assertStringContainsString('href="#identifier"', $this->summaryRegion($html));
        $this->assertStringContainsString('id="identifier"', $html);
    }

    public function test_register_duplicate_account_error_renders_inside_the_summary(): void
    {
        // The cross-field ("account") message: before the class
        // fix it rendered as a banner-key paragraph only when the
        // page opted in; now it is an item in the one summary list.
        $this->seed(TwoBusinessFixtureSeeder::class);

        $this->post(route('register'), [
            'business_name' => 'Corner Deli',
            'name' => 'Dana Owner',
            'email' => TwoBusinessFixtureSeeder::A_EMAIL,
            'phone' => '(718) 555-0199',
            'password' => 'a long phrase nobody else uses 42',
            'password_confirmation' => 'a long phrase nobody else uses 42',
            'country' => 'US',
            'street' => '1 Main Street',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
        ])->assertSessionHasErrors(['account' => 'An account with these details already exists. Sign in or reset your password.']);

        $html = $this->get(route('register'))->assertOk()->getContent();

        $this->assertMessageInSummary($html, 'An account with these details already exists. Sign in or reset your password.');
    }

    public function test_register_field_error_renders_inside_the_summary(): void
    {
        $this->post(route('register'), [
            'business_name' => '',
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
        ])->assertSessionHasErrors(['business_name' => 'Enter your business name.']);

        $html = $this->get(route('register'))->assertOk()->getContent();

        $this->assertMessageInSummary($html, 'Enter your business name.');
        $this->assertStringContainsString('href="#business_name"', $this->summaryRegion($html));
        $this->assertStringContainsString('id="business_name"', $html);
    }

    public function test_forgot_password_invalid_email_renders_inside_the_summary(): void
    {
        $this->post(route('password.email'), ['email' => 'not-an-email'])
            ->assertSessionHasErrors(['email' => 'The email field must be a valid email address.']);

        $html = $this->get(route('password.request'))->assertOk()->getContent();

        $this->assertMessageInSummary($html, 'The email field must be a valid email address.');
    }

    public function test_reset_password_weak_password_renders_inside_the_summary(): void
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
        ])->assertSessionHasErrors(['password' => 'The password field must be at least 12 characters.']);

        $html = $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()->getContent();

        $this->assertMessageInSummary($html, 'The password field must be at least 12 characters.');
    }

    public function test_enroll_without_consent_names_it_inside_the_summary(): void
    {
        // r28/r29 regression guard: the enroll surface that
        // produced this defect class keeps listing its problem.
        $business = World::business();
        [, $qrToken] = World::qr($business);

        $this->carryFlowCookie($this->get(route('refer.landing', ['token' => $qrToken]))->assertOk());
        $this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111',
        ])->assertSessionHasErrors(['consent' => 'Please agree to the texts so we can send your code and your reward updates.']);

        $html = $this->get(route('refer.landing', ['token' => $qrToken]))->assertOk()->getContent();

        $this->assertMessageInSummary($html, 'Please agree to the texts so we can send your code and your reward updates.');
        // Summary + field, exactly twice (the RT-3 design): the
        // class fix must not triple-print a surface that already
        // listed correctly.
        $this->assertSame(2, substr_count($html, 'Please agree to the texts so we can send your code and your reward updates.'));
    }

    public function test_friend_claim_without_consent_names_it_inside_the_summary(): void
    {
        // r36 regression guard (RT-3): same shape as enroll.
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [, $shareToken] = World::membership($business, $referrer);

        $this->carryFlowCookie($this->get(route('refer.friend', ['token' => $shareToken]))->assertOk());
        $this->post(route('refer.friend.claim', ['token' => $shareToken]), [
            'first_name' => 'Jo', 'last_name' => 'Kim', 'phone' => '2125550212', 'send_link' => '1',
        ])->assertSessionHasErrors(['consent' => 'Please agree to the texts so we can send your code and your reward updates.']);

        $html = $this->get(route('refer.friend', ['token' => $shareToken]))->assertOk()->getContent();

        $this->assertMessageInSummary($html, 'Please agree to the texts so we can send your code and your reward updates.');
        $this->assertSame(2, substr_count($html, 'Please agree to the texts so we can send your code and your reward updates.'));
    }

    public function test_marketing_newsletter_invalid_email_renders_inside_the_summary(): void
    {
        $this->post('/newsletter', ['email' => 'not-an-email'])
            ->assertSessionHasErrors(['email' => 'The email field must be a valid email address.']);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMessageInSummary($html, 'The email field must be a valid email address.');
    }

    public function test_profile_invalid_save_names_every_problem_inside_the_summary(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('settings.profile.update'), [
            'name' => '',
            'contact_email' => 'not-an-email',
            'contact_phone' => '(212) 555-0401',
            'street' => '9 New Street',
            'city' => 'Albany',
            'state' => 'NY',
            'postal_code' => '12207',
            'country' => 'US',
        ])->assertSessionHasErrors([
            'name' => 'Enter your business name.',
            'contact_email' => 'Enter a valid email address.',
        ]);

        $html = $this->actingAs($owner)->get(route('settings.profile'))->assertOk()->getContent();

        $this->assertMessageInSummary($html, '2 things to fix:');
        $this->assertMessageInSummary($html, 'Enter your business name.');
        $this->assertMessageInSummary($html, 'Enter a valid email address.');
        // Exactly one summary on the page (the deleted in-panel
        // component must not leave a second one behind).
        $this->assertSame(1, substr_count($html, 'data-error-summary>'));
    }

    public function test_settings_rewards_invalid_vesting_names_it_inside_the_summary(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('settings.rewards.update'), [
            'friend_reward_text' => 'Free pastry with your first coffee',
            'referrer_reward_text' => '',
            'redemption_window_days' => 30,
            'reward_vesting_days' => 6,
        ])->assertSessionHasErrors(['reward_vesting_days' => 'Enter a whole number of days, at least 7.']);

        $html = $this->actingAs($owner)->get(route('settings.rewards'))->assertOk()->getContent();

        $this->assertMessageInSummary($html, 'Enter a whole number of days, at least 7.');
    }

    public function test_password_change_wrong_current_password_names_it_inside_the_summary(): void
    {
        // The password form validates into the named "password"
        // bag: before the class fix its messages were invisible
        // to the (default-bag-only) summary entirely.
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->put(route('security.password'), [
            'current_password' => 'definitely not the password',
            'password' => 'a brand new long phrase 99',
            'password_confirmation' => 'a brand new long phrase 99',
        ])->assertSessionHasErrorsIn('password', ['current_password']);

        $html = $this->actingAs($owner)->get(route('security'))->assertOk()->getContent();

        $this->assertMessageInSummary($html, 'The provided password is incorrect.');
    }

    public function test_customer_create_names_every_problem_inside_the_summary_with_live_anchors(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->from(route('customers.create'))->post(route('customers.store'), [
            'first_name' => '',
            'phone' => 'not-a-phone',
        ])->assertSessionHasErrors(['first_name', 'phone']);

        $html = $this->actingAs($owner)->get(route('customers.create'))->assertOk()->getContent();

        $region = $this->summaryRegion($html);
        $this->assertMessageInSummary($html, 'Enter the customer name.');
        $this->assertMessageInSummary($html, 'Enter a valid phone number.');
        // Complex-form anchor check: both summary anchors resolve
        // to real field ids on the page.
        $this->assertStringContainsString('href="#first_name"', $region);
        $this->assertStringContainsString('href="#phone"', $region);
        $this->assertStringContainsString('id="first_name"', $html);
        $this->assertStringContainsString('id="phone"', $html);
        $this->assertSame(1, substr_count($html, 'data-error-summary>'));
    }

    public function test_campaign_composer_empty_body_names_it_inside_the_summary(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('campaigns.store'), ['body' => ''])
            ->assertSessionHasErrors(['body' => 'The body field is required.']);

        $html = $this->actingAs($owner)->get(route('campaigns.create'))->assertOk()->getContent();

        $this->assertMessageInSummary($html, 'The body field is required.');
    }

    public function test_billing_card_declined_names_it_inside_the_summary(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->gateway->cardFailureCode = 'GENERIC_DECLINE';

        $this->actingAs($owner)->post(route('settings.billing.card.store'), [
            'source_id' => 'cnon:declined',
        ])->assertSessionHasErrors(['card' => 'Your card was declined. No charge was made. Check the details or try a different card.']);

        $html = $this->actingAs($owner)->get(route('settings.billing'))->assertOk()->getContent();

        $this->assertMessageInSummary($html, 'Your card was declined. No charge was made. Check the details or try a different card.');
    }

    public function test_billing_card_missing_source_names_it_inside_the_summary(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('settings.billing.card.store'), [])
            ->assertSessionHasErrors(['source_id' => 'Some card details need another look.']);

        $html = $this->actingAs($owner)->get(route('settings.billing'))->assertOk()->getContent();

        $this->assertMessageInSummary($html, 'Some card details need another look.');
    }

    public function test_onboarding_card_declined_names_it_inside_the_summary(): void
    {
        $business = World::business([
            'billing_status' => 'none',
            'friend_reward_text' => null,
            'referrer_reward_text' => null,
            'email_verified_at' => null,
        ]);
        $owner = World::owner($business);
        $owner->forceFill(['email_verified_at' => null])->save();
        $this->gateway->cardFailureCode = 'GENERIC_DECLINE';

        $this->actingAs($owner)->post(route('onboarding.card.store'), [
            'source_id' => 'cnon:declined',
        ])->assertSessionHasErrors(['card' => 'Your card was declined. No charge was made. Check the details or try a different card.']);

        $html = $this->actingAs($owner)->get(route('onboarding.step', 'card'))->assertOk()->getContent();

        $this->assertMessageInSummary($html, 'Your card was declined. No charge was made. Check the details or try a different card.');
    }

    public function test_onboarding_rewards_empty_text_names_it_inside_the_summary(): void
    {
        $business = World::business([
            'billing_status' => 'none',
            'friend_reward_text' => null,
            'referrer_reward_text' => null,
            'email_verified_at' => null,
        ]);
        $owner = World::owner($business);
        $owner->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($owner)->post(route('owner.reward-texts'), [
            'friend_reward_text' => '',
        ])->assertSessionHasErrors(['friend_reward_text' => 'The friend reward text field is required.']);

        $html = $this->actingAs($owner)->get(route('onboarding.step', 'rewards'))->assertOk()->getContent();

        $this->assertMessageInSummary($html, 'The friend reward text field is required.');
    }

    public function test_admin_login_bad_credentials_name_it_inside_the_summary(): void
    {
        User::create([
            'business_id' => null,
            'role' => 'platform_admin',
            'name' => 'Platform Admin',
            'email' => 'admin1@example.test',
            'canonical_email' => CanonicalEmail::of('admin1@example.test'),
            'phone_e164' => '+12125550901',
            'password' => Hash::make('a long admin phrase 123'),
            'email_verified_at' => now(),
        ]);

        $this->post(route('admin.login.store'), [
            'email' => 'admin1@example.test',
            'password' => 'wrong phrase entirely',
        ])->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);

        $html = $this->get(route('admin.login'))->assertOk()->getContent();

        $this->assertMessageInSummary($html, 'These credentials do not match our records.');
    }
}
