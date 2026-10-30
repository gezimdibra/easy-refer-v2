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
        // EU-S2: custom shell view; lines kept as the content record.
        $lines = [
            'The password for your Easy Refer owner account was just changed.',
            'If this was not you, sign in and change your password right away, then contact support so we can help secure your account.',
        ];
        $url = route('login');

        $mail = (new MailMessage)
            ->subject('Your Easy Refer password was changed')
            ->action('Sign in', $url)
            ->view('emails.notifications.notice', [
                'headline' => 'Your Easy Refer password was changed',
                'lines' => $lines,
                'url' => $url,
                'actionLabel' => 'Sign in',
            ]);
        foreach ($lines as $line) {
            $mail->line($line);
        }

        return $mail;
    }
}
