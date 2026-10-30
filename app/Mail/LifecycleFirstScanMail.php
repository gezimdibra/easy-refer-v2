<?php

namespace App\Mail;

use App\Models\Business;
use App\Models\ReferralEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * D3, the no-scan stall drip email (M-5 design pass Sections
 * 3.3 and 6.3; copy-inventory Amendment 72, [M5-D3-*]). This
 * is the shipped form of the [NUDGE-01] no-scan idea,
 * re-triggered on scans and re-copied under state-truth
 * rules. Sent by LifecycleMailService exactly once per
 * business, only while the flow is live, a poster was taken,
 * and zero scan events exist at send time.
 *
 * {days_live} is the whole days since the FIRST poster event
 * (the ReferralEvent row's created_at), minimum 1 in this
 * state; if no poster row can be read at render, the clause
 * is cut and the body's fallback opening renders (pass
 * Section 6.3). A day count from account age is never
 * substituted.
 *
 * Deliberately NOT ShouldQueue: renders at send time from
 * live facts (pass Section 4.1).
 */
class LifecycleFirstScanMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Business $business) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Try your poster by the register');
    }

    public function content(): Content
    {
        $posterAt = ReferralEvent::query()
            ->where('business_id', $this->business->id)
            ->where('event_type', 'poster_downloaded')
            ->orderBy('created_at')
            ->value('created_at');

        return new Content(view: 'emails.lifecycle-first-scan', with: [
            'business' => $this->business,
            'mailTitle' => 'Try your poster by the register',
            'daysLive' => $posterAt === null
                ? null
                : max(1, (int) $posterAt->diffInDays(now())),
            'actionUrl' => route('owner.qr'),
        ]);
    }
}
