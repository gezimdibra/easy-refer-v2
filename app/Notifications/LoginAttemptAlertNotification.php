<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Owner alert for the per-account aggregate login defense (spec Section
 * 8.3): sent once when failures against the account, counted across all
 * source IPs, cross the platform threshold. Carries no addresses or
 * identifiers beyond the count and the protective action taken.
 */
class LoginAttemptAlertNotification extends Notification
{
    public function __construct(private int $failureCount) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // EU-S2: custom shell view; lines kept as the content record.
        $lines = [
            "We recorded {$this->failureCount} failed sign-in attempts against your account in the last hour.",
            'To protect the account, sign-in is temporarily locked. Use the unlock link in the separate email about unlocking your account, or wait for the lock to pass.',
            'If this was you, use the unlock link or wait for the lock to pass. If it was not you, change your password after signing in and review the sessions on your security page.',
        ];

        $mail = (new MailMessage)
            ->subject('Unusual sign-in activity on your Easy Refer account')
            ->view('emails.notifications.notice', [
                'headline' => 'Unusual sign-in activity on your Easy Refer account',
                'lines' => $lines,
            ]);
        foreach ($lines as $line) {
            $mail->line($line);
        }

        return $mail;
    }
}
