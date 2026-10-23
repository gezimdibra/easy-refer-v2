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
        return (new MailMessage)
            ->subject('Unusual sign-in activity on your Easy Refer account')
            ->line("We recorded {$this->failureCount} failed sign-in attempts against your account in the last hour, from multiple locations.")
            ->line('To protect the account, sign-in is temporarily locked unless the visitor completes an additional verification step.')
            ->line('If this was you, wait for the lock to pass or complete the verification on the sign-in page. If it was not you, change your password after signing in and review the sessions on your security page.');
    }
}
