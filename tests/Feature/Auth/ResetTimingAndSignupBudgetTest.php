<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Database\Seeders\TwoBusinessFixtureSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Timing and budget hardening for the reset and signup paths (spec
 * Section 8.3; security findings L-3 and L-4). The reset mail is
 * queued so mailer latency cannot separate existing from nonexistent
 * accounts, and signup attempts are budgeted per identifier, not only
 * per source address.
 */
class ResetTimingAndSignupBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['https://api.pwnedpasswords.com/*' => Http::response('', 200)]);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function signupPayload(array $overrides = []): array
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

    public function test_the_reset_notification_is_a_queued_notification(): void
    {
        $this->assertInstanceOf(ShouldQueue::class, new ResetPasswordNotification('token'));
    }

    public function test_a_reset_request_enqueues_the_mail_instead_of_sending_it_inline(): void
    {
        config(['queue.default' => 'database']);

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHas('status', 'If an account exists for that email, a reset link is on its way.');

        // One queued notification job exists; nothing was sent inline.
        $this->assertSame(1, DB::table('jobs')->count());
        $job = DB::table('jobs')->first();
        $this->assertStringContainsString('ResetPasswordNotification', (string) $job->payload);

        // The nonexistent-account path enqueues nothing and answers
        // with the identical generic status.
        $this->post(route('password.email'), ['email' => 'ghost@example.test'])
            ->assertSessionHas('status', 'If an account exists for that email, a reset link is on its way.');

        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_the_reset_round_trip_still_works_with_the_queued_notification(): void
    {
        // The suite's sync queue executes the job inline, so the full
        // round trip continues to behave exactly as before.
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a fresh long password 99',
            'password_confirmation' => 'a fresh long password 99',
        ])->assertRedirect(route('login'));
    }

    public function test_signup_attempts_against_one_email_are_budgeted_across_source_ips(): void
    {
        $max = (int) config('security.signup_throttle.per_identifier_max_attempts');

        // Every attempt fails an unrelated rule (a too-short
        // password; RS2-2: the address rules this test used to
        // trip live at the onboarding Location step now, not at
        // signup), so no account is ever created; the identifier
        // budget still counts each one, and each arrives from a
        // different address.
        for ($i = 1; $i <= $max; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$i])
                ->post(route('register'), $this->signupPayload(['password' => 'short', 'password_confirmation' => 'short']))
                ->assertSessionHasErrors('password');
        }

        // The next attempt, even fully valid and from a fresh address,
        // is refused by the per-identifier budget.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.250'])
            ->post(route('register'), $this->signupPayload())
            ->assertSessionHasErrors('account');

        $message = session('errors')->get('account')[0];
        $this->assertStringContainsString('Too many account creation attempts', $message);
        $this->assertSame(0, User::count());
    }

    public function test_signup_attempts_against_one_phone_are_budgeted_across_source_ips(): void
    {
        $max = (int) config('security.signup_throttle.per_identifier_max_attempts');

        for ($i = 1; $i <= $max; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$i])
                ->post(route('register'), $this->signupPayload([
                    'email' => 'person'.$i.'@example.test',
                    'password' => 'short',
                    'password_confirmation' => 'short',
                ]))
                ->assertSessionHasErrors('password');
        }

        // A fresh email does not help: the shared phone is spent.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.250'])
            ->post(route('register'), $this->signupPayload(['email' => 'fresh@example.test']))
            ->assertSessionHasErrors('account');

        $this->assertSame(0, User::count());
    }

    public function test_distinct_identifiers_have_distinct_signup_budgets(): void
    {
        $this->seed(TwoBusinessFixtureSeeder::class);

        // Owner A signs up fresh data; owner B's identity is a
        // different budget entirely, so a normal signup still works.
        $this->post(route('register'), $this->signupPayload())
            ->assertRedirect(route('onboarding.welcome'));
    }
}
