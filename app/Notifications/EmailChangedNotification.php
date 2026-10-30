<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the OLD address when the account email changes (spec
 * Section 8.5). The old mailbox learns that the account moved and
 * where it moved to, so a legitimate owner who did not make the
 * change has the facts needed to contact support immediately.
 */
class EmailChangedNotification extends Notification
{
    public function __construct(public readonly string $newEmail) {}

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
            'The email address on your Easy Refer owner account was changed to '.$this->newEmail.'.',
            'The new address is not verified yet. Verify it so your receipts and password resets reach you.',
            'If this was not you, contact support right away so we can help secure your account.',
        ];

        $mail = (new MailMessage)
            ->subject('Your Easy Refer account email was changed')
            ->view('emails.notifications.notice', [
                'headline' => 'Your Easy Refer account email was changed',
                'lines' => $lines,
            ]);
        foreach ($lines as $line) {
            $mail->line($line);
        }

        return $mail;
    }
}
