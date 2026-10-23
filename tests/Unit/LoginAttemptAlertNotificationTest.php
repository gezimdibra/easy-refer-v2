<?php

namespace Tests\Unit;

use App\Models\User;
use App\Notifications\LoginAttemptAlertNotification;
use PHPUnit\Framework\TestCase;

/**
 * The aggregate-defense owner alert (spec Section 8.3): mail channel,
 * a subject that says what happened, and a body carrying the failure
 * count and the protective action, nothing else sensitive.
 */
class LoginAttemptAlertNotificationTest extends TestCase
{
    public function test_the_alert_mail_content(): void
    {
        $notification = new LoginAttemptAlertNotification(25);
        $user = new User(['name' => 'Owner', 'email' => 'owner@example.test']);

        $this->assertSame(['mail'], $notification->via($user));

        $mail = $notification->toMail($user);

        $this->assertSame('Unusual sign-in activity on your Easy Refer account', $mail->subject);
        $this->assertStringContainsString('25 failed sign-in attempts', implode(' ', $mail->introLines));
    }
}
