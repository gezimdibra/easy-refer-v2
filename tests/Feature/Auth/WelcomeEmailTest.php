<?php

namespace Tests\Feature\Auth;

use App\Mail\WelcomeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The welcome email (saas-needs lifecycle item 1): queued exactly
 * once by the registration flow, never by verification resends,
 * duplicate attempts, or direct model creation (the seeded and
 * demo account path), and rendered in the product's mail design
 * system from approved copy only.
 */
class WelcomeEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Same breach-corpus stub as RegistrationTest: signup
        // password screening must not leave the test sandbox.
        Http::fake(fn () => Http::response('', 200));
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

    public function test_registration_queues_the_welcome_email_exactly_once(): void
    {
        Notification::fake();
        Mail::fake();

        $this->post(route('register'), $this->payload())->assertRedirect(route('dashboard'));

        Mail::assertQueued(WelcomeMail::class, 1);
        Mail::assertQueued(WelcomeMail::class, fn (WelcomeMail $mail) => $mail->hasTo('dana@example.test')
            && $mail->user->email === 'dana@example.test');
    }

    public function test_verification_resend_queues_no_second_welcome(): void
    {
        Notification::fake();
        Mail::fake();

        $this->post(route('register'), $this->payload())->assertRedirect(route('dashboard'));
        Mail::assertQueued(WelcomeMail::class, 1);

        $this->post(route('verification.send'))->assertRedirect();
        Notification::assertSentTo(User::firstOrFail(), \Illuminate\Auth\Notifications\VerifyEmail::class);

        Mail::assertQueued(WelcomeMail::class, 1);
    }

    public function test_duplicate_registration_attempt_queues_no_second_welcome(): void
    {
        Notification::fake();
        Mail::fake();

        $this->post(route('register'), $this->payload())->assertRedirect(route('dashboard'));
        $this->post(route('logout'));

        $this->post(route('register'), $this->payload())
            ->assertSessionHasErrors('account');

        Mail::assertQueued(WelcomeMail::class, 1);
    }

    public function test_factory_created_accounts_queue_no_welcome(): void
    {
        Mail::fake();

        // The seeded and demo account path: models created
        // directly never pass through the registration flow.
        User::factory()->create();

        Mail::assertNothingQueued();
    }

    public function test_welcome_subject_and_unverified_render_carry_the_required_elements(): void
    {
        $user = User::factory()->unverified()->create();

        $mail = new WelcomeMail($user);

        $this->assertSame(
            'Welcome to Easy Refer: one step left before your code works',
            $mail->envelope()->subject,
        );

        $html = $mail->render();

        // Required: the greeting, what they joined, the one next
        // action with the repeated verification link, and where
        // the dashboard and help live.
        $this->assertStringContainsString('Welcome to Easy Refer', $html);
        $this->assertStringContainsString($user->business->name, $html);
        $this->assertStringContainsString('Pay Per Visit', $html);
        $this->assertStringContainsString('you pay $2 only when a referred friend actually visits your store and redeems', $html);
        $this->assertStringContainsString('first 5 verified visits are free', $html);
        $this->assertStringContainsString('never pay more than $99 in a month', $html);
        $this->assertStringContainsString('Nothing is charged today', $html);
        $this->assertStringContainsString('One step left before your code works: verify this email address', $html);
        $this->assertStringContainsString('Verify your email address', $html);
        $this->assertStringContainsString('verify-email', $html);
        $this->assertStringContainsString('signature=', $html);
        $this->assertStringContainsString(route('dashboard'), $html);
        $this->assertStringContainsString(route('faq'), $html);
        $this->assertStringContainsString('support@easy-refer.com', $html);

        $this->assertBannedContentAbsent($html);
    }

    public function test_verified_owner_render_points_at_the_qr_page_instead(): void
    {
        $user = User::factory()->create();
        $this->assertTrue($user->hasVerifiedEmail());

        $html = (new WelcomeMail($user))->render();

        $this->assertStringContainsString('Your email address is verified', $html);
        $this->assertStringContainsString('Get your QR code', $html);
        $this->assertStringContainsString(route('owner.qr'), $html);
        $this->assertStringNotContainsString('Verify your email address', $html);
        $this->assertStringNotContainsString('verify-email', $html);
        $this->assertStringNotContainsString('signature=', $html);

        $this->assertBannedContentAbsent($html);
    }

    private function assertBannedContentAbsent(string $html): void
    {
        // Retired Pine values (plain forms) and the retired
        // cobalt, from the Lane C final-color sweep set.
        foreach (['#0b6142', '#095139', '#07402d', '#127a54', '#cdebdb', '#edf7f1', '#2563eb'] as $retired) {
            $this->assertStringNotContainsString($retired, $html);
            $this->assertStringNotContainsString(strtoupper($retired), $html);
        }

        $this->assertStringNotContainsString('—', $html);
        $this->assertStringNotContainsString('Lorem', $html);
        $this->assertStringNotContainsString('{verification_link}', $html);
        $this->assertStringNotContainsString('TODO', $html);
    }
}
