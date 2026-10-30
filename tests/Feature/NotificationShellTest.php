<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AccountUnlockNotification;
use App\Notifications\EmailChangedNotification;
use App\Notifications\LoginAttemptAlertNotification;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EU-S2 (R5 pack B0 finding 5): the vendor-themed notification
 * family moves onto the custom shell. Every notification renders
 * the branded card (mark + wordmark header, support footer, the
 * EU-S1 dark classes), none renders the framework greeting, and
 * the reset mail carries the branded subject instead of the
 * framework default.
 */
class NotificationShellTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create();
    }

    /** @return array<string, array{0: object, 1: string}> notification + expected subject */
    private function cases(): array
    {
        return [
            'verify' => [new VerifyEmailNotification, 'Verify Email Address'],
            'reset' => [new ResetPasswordNotification('token-abc'), 'Reset your Easy Refer password'],
            'unlock' => [new AccountUnlockNotification('token-abc'), 'Unlock your Easy Refer account'],
            'email-changed' => [new EmailChangedNotification('new@example.test'), 'Your Easy Refer account email was changed'],
            'login-alert' => [new LoginAttemptAlertNotification(9), 'Unusual sign-in activity on your Easy Refer account'],
            'password-changed' => [new PasswordChangedNotification, 'Your Easy Refer password was changed'],
        ];
    }

    public function test_every_notification_is_wired_to_the_custom_shell(): void
    {
        $user = $this->user();

        foreach ($this->cases() as $name => [$notification, $subject]) {
            $mail = $notification->toMail($user);
            $this->assertSame($subject, $mail->subject, $name);
            $this->assertNotNull($mail->view, $name.' carries a custom view');
            $this->assertStringStartsWith('emails.notifications.', (string) $mail->view, $name);

            $html = view($mail->view, $mail->viewData)->render();
            $this->assertStringContainsString('mail-mark.png', $html, $name.': branded header mark');
            $this->assertStringContainsString('class="em-wordmark"', $html, $name.': wordmark on the shell');
            $this->assertStringNotContainsString('Hello!', $html, $name.': no framework greeting');
            $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html, $name.': dark scheme present');
        }
    }

    public function test_reset_mail_keeps_the_framework_body_facts(): void
    {
        $mail = (new ResetPasswordNotification('token-abc'))->toMail($this->user());
        $html = view($mail->view, $mail->viewData)->render();

        $this->assertStringContainsString('password reset request for your account', $html);
        // OB-07 (Amendment 45): the expiry claim is anchored to
        // the token's creation, never a fresh 60 minutes stated
        // at render; this direct construction has no token row,
        // so the bounded softened form renders.
        $this->assertStringContainsString('expires within the hour', $html);
        $this->assertStringNotContainsString('will expire in 60 minutes', $html);
        $this->assertStringContainsString('Reset Password', $html);
    }
}
