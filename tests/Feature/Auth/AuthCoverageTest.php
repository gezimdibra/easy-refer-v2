<?php

namespace Tests\Feature\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use Database\Seeders\TwoBusinessFixtureSeeder;
use App\Notifications\ResetPasswordNotification as ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Branch-level coverage for the auth surface (protocol Section 2.1:
 * the auth module carries a 100 percent coverage gate). Each test here
 * exists because it exercises one specific branch the flow tests do
 * not reach.
 */
class AuthCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_user_sees_the_verification_prompt(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get(route('verification.notice'))
            ->assertOk()
            ->assertSee('Verify your email address');
    }

    public function test_verified_user_hitting_the_verification_prompt_is_sent_to_the_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('verification.notice'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_verified_user_resending_verification_is_redirected_without_sending(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('verification.send'))
            ->assertRedirect(route('dashboard'));

        Notification::assertNothingSent();
    }

    public function test_already_verified_user_following_the_signed_link_just_redirects(): void
    {
        $user = User::factory()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect(route('dashboard').'?verified=1');
    }

    public function test_password_reset_request_stops_sending_once_the_per_email_budget_is_spent(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $budgetKey = 'password-reset:'.hash('sha256', $user->canonical_email);
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit($budgetKey, 3600);
        }

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHas('status', 'If an account exists for that email, a reset link is on its way.');

        Notification::assertNotSentTo($user, ResetPassword::class);
    }

    public function test_login_upgrades_a_legacy_bcrypt_hash_to_argon2id(): void
    {
        $user = User::factory()->create();

        // Written directly, as a pre-existing database row would hold it;
        // the hashed cast deliberately refuses foreign-algorithm hashes
        // assigned through the model.
        DB::table('users')->where('id', $user->id)->update([
            'password' => password_hash('legacy-password-123', PASSWORD_BCRYPT),
        ]);

        $this->post(route('login'), [
            'identifier' => $user->email,
            'password' => 'legacy-password-123',
        ])->assertRedirect(route('dashboard'));

        $this->assertSame('argon2id', password_get_info($user->fresh()->password)['algoName']);
    }

    #[Group('slow')]
    public function test_repeated_lockouts_escalate_and_block_even_with_the_attempts_window_cleared(): void
    {
        $this->seed(TwoBusinessFixtureSeeder::class);
        $attemptsKey = LoginRequest::throttleKeyFor(TwoBusinessFixtureSeeder::A_EMAIL, '127.0.0.1');

        // Three full lockout cycles. Clearing the attempts key between
        // cycles models the one-minute window expiring, as real time
        // passing would; the lockout counter persists across windows.
        for ($cycle = 0; $cycle < 3; $cycle++) {
            RateLimiter::clear($attemptsKey);

            for ($i = 0; $i < 5; $i++) {
                $this->post(route('login'), [
                    'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
                    'password' => 'wrong password',
                ]);
            }
        }

        RateLimiter::clear($attemptsKey);

        $this->post(route('login'), [
            'identifier' => TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => TwoBusinessFixtureSeeder::A_PASSWORD,
        ])->assertSessionHasErrors('identifier');

        $this->assertStringContainsString('Too many attempts. Please wait a few minutes and try again.', session('errors')->get('identifier')[0]);
        $this->assertGuest();
    }

    public function test_password_changed_notification_builds_a_mail_with_the_login_link(): void
    {
        $user = User::factory()->create();

        $mail = (new PasswordChangedNotification)->toMail($user);

        $this->assertSame('Your Easy Refer password was changed', $mail->subject);
        $this->assertSame(route('login'), $mail->actionUrl);
    }

    public function test_identity_model_relations_and_helpers(): void
    {
        $business = Business::factory()->create(['status' => 'active']);
        $owner = User::factory()->for($business)->create(['role' => 'owner']);

        $this->assertTrue($business->isActive());
        $this->assertSame($owner->id, $business->owner->id);
        $this->assertTrue($business->users->contains($owner));
        $this->assertTrue($owner->isOwner());
        $this->assertSame($business->id, $owner->business->id);

        $log = AuditLog::write('test.event', $owner, ['k' => 'v'], $business);
        $this->assertSame($business->id, $log->business->id);
        $this->assertSame($owner->id, $log->user->id);
        $this->assertTrue($business->auditLogs->contains($log));
        $this->assertSame(['k' => 'v'], $log->metadata);
    }
}
