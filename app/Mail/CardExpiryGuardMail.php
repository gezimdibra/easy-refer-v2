<?php

namespace App\Mail;

use App\Models\Business;
use App\Models\PaymentMethod;
use App\Support\BillingForecast;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * The card-expiry guard email (RK-03; inventory BILL-FC-03):
 * sent by the daily forecast-reminders run once per charge
 * cycle, inside the 30 days before the card's expiry, only
 * when the card on file expires before the next charge date
 * (the Action Center's AC-08 predicate). This email trigger
 * (T-30 of expiry) is deliberately NOT the in-app trigger:
 * SV-02's billing-page pill keeps its own 60-day window.
 */
class CardExpiryGuardMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Business $business,
        public PaymentMethod $card,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your card expires before your next charge');
    }

    public function content(): Content
    {
        $expiry = Carbon::create($this->card->exp_year, $this->card->exp_month, 1);

        return new Content(view: 'emails.card-expiry-guard', with: [
            'business' => $this->business,
            // B1: the fact well's Card row (brand + last4, the only
            // card facts the server holds).
            'cardLabel' => trim(((string) $this->card->card_brand).' ···· '.((string) $this->card->last4)),
            'expiryMonthYear' => $expiry->format('F Y'),
            'chargeDate' => BillingForecast::nextChargeDateFor($this->business)->format('F j'),
            'billingUrl' => route('settings.billing'),
        ]);
    }
}
