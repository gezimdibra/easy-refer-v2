<?php

namespace App\Mail;

use App\Models\Business;
use App\Models\PaymentMethod;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * C1, the card-has-expired warning (M-5 design pass Sections
 * 4.5 and 6.6; copy-inventory Amendment 72, [M5-C1-*]). It
 * closes the expiry guard's tail: the guard's window ends at
 * the expiry month's end, and this mail owns the state after
 * it, before any charge has failed. Sent by
 * LifecycleMailService exactly once per card (the ledger key
 * carries the payment method id), only while
 * ActionCenter::cardIsExpired reads true at send time and
 * the business is not past due.
 *
 * The "Your code keeps working" sentence renders only while
 * Business::flowIsActive() is true at render; in any other
 * state the sentence is cut by branch rather than left
 * standing (pass Section 6.6). The card label is brand and
 * last 4 only, the guard mail's B1 construction.
 *
 * Deliberately NOT ShouldQueue: renders at send time from
 * live facts (pass Section 4.1).
 */
class CardExpiredMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Business $business,
        public PaymentMethod $card,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your card on file has expired');
    }

    public function content(): Content
    {
        $expiry = Carbon::create($this->card->exp_year, $this->card->exp_month, 1);
        $cardLabel = trim(((string) $this->card->card_brand).' ···· '.((string) $this->card->last4));

        return new Content(view: 'emails.card-expired', with: [
            'business' => $this->business,
            'mailTitle' => 'Your card on file has expired',
            'cardLabel' => $cardLabel,
            'expiryMonthYear' => $expiry->format('F Y'),
            'keepsWorking' => $this->business->flowIsActive(),
            'actionUrl' => route('settings.billing'),
        ]);
    }
}
