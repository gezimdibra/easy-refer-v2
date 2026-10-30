<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordBase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The password reset email (spec Sections 4.3 and 8.3), queued.
 *
 * Queueing is a security property here, not only a performance one
 * (finding L-3): when the mail was sent synchronously, the response
 * for an existing account also paid the mailer latency, so the two
 * reset paths sat in measurably different timing classes. With the
 * send queued, both paths do the same work in the request (token
 * generation plus one Argon2id-class verify) and answer identically.
 */
class ResetPasswordNotification extends ResetPasswordBase implements ShouldQueue
{
    use Queueable;

    /**
     * EU-S2 (R5 pack B1): the reset email on the custom shell with
     * the branded subject (Amendment 26 R5-MAIL-01, PROPOSED; was
     * the framework default "Reset Password Notification"). The
     * body sentences are the framework's, verbatim, and the URL
     * and expiry are the base class's own construction.
     */
    public function toMail($notifiable): MailMessage
    {
        $url = url(config('app.url').route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        $expire = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        // OB-07 (Amendment 45): the broker token's expiry runs
        // from the reset REQUEST, but this queued notification
        // is composed later; the old "will expire in {expire}
        // minutes" therefore overstated the remaining time by
        // however long the queue stalled. The sentence is now
        // anchored to the token row's creation: an absolute
        // expiry time. With no token row to anchor on (a shape
        // the broker never produces for a real send), the claim
        // softens to the bounded form instead of inventing one.
        // Two-key Condition 2 (2026-10-28): the absolute time
        // renders in the recipient business's local time (the
        // Business::localTime convention the claim unlock time
        // uses), never as a bare UTC clock reading the owner
        // would take for their own wall clock.
        $expiryLine = $this->expiryLine($notifiable, $expire);

        $lines = [
            'You are receiving this email because we received a password reset request for your account.',
            $expiryLine,
            'If you did not request a password reset, no further action is required.',
        ];

        $mail = (new MailMessage)
            ->subject('Reset your Easy Refer password')
            ->action('Reset Password', $url)
            ->view('emails.notifications.reset', ['url' => $url, 'expire' => $expire, 'expiryLine' => $expiryLine]);
        foreach ($lines as $line) {
            $mail->line($line);
        }

        return $mail;
    }

    private function expiryLine(object $notifiable, int $expireMinutes): string
    {
        $row = \Illuminate\Support\Facades\DB::table('password_reset_tokens')
            ->where('email', $notifiable->getEmailForPasswordReset())
            ->first();

        if ($row === null || $row->created_at === null) {
            return 'This password reset link expires within the hour.';
        }

        $expiresAt = \Illuminate\Support\Carbon::parse($row->created_at)->addMinutes($expireMinutes);

        // The notifiable is an owner account; its business
        // carries the wall clock the owner reads times on.
        // Localize through the same machinery as every other
        // exact owner-facing time. A notifiable with no
        // business context (no production path produces one)
        // gets the zone labeled explicitly instead of a bare
        // reading in a frame the reader cannot know.
        $business = $notifiable->business ?? null;

        // TS3-07 (Sweep-03, Amendment 52): the expiry renders
        // WITH its date. A time alone is ambiguous across
        // midnight (a 12:30 AM tomorrow expiry reads the same
        // as one 23.5 hours stale), and this queued mail can
        // be composed after a stall; the date keeps the claim
        // checkable in every state it renders in.
        if ($business !== null && method_exists($business, 'localTime')) {
            $local = $business->localTime($expiresAt);

            return 'This password reset link will expire at '.$local->format('g:i A').' on '.$local->format('M j').'.';
        }

        return 'This password reset link will expire at '.$expiresAt->format('g:i A T').' on '.$expiresAt->format('M j').'.';
    }
}
