<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent whenever an account password changes, by reset or from the
 * security page (spec Sections 4.3 and 4.4).
 */
class PasswordChangedNotification extends Notification
{
    use Queueable;

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
            ->subject('Your Easy Refer password was changed')
            ->line('The password for your Easy Refer owner account was just changed.')
            ->line('If this was not you, sign in and change your password right away, then contact support so we can help secure your account.')
            ->action('Sign in', route('login'));
    }
}
