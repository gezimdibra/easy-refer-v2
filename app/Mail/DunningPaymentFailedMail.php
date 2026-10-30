<?php

namespace App\Mail;

use App\Models\BillingRecord;
use App\Models\Business;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Dunning day 0 (spec Section 9.6; inventory DUN-01 subject,
 * DUN-02 body): sent by the dunning run once a webhook-driven
 * failure has put the business past_due. The amount is the
 * failed record's own amount; the grace end is the past_due
 * entry plus the specced grace window. The billing link is a
 * deep link into the authenticated billing page: it lands on
 * the login when signed out, authorizes nothing by itself,
 * and the Section 8.7 step-up still guards the card change
 * (PD-3).
 */
class DunningPaymentFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Business $business,
        public BillingRecord $record,
        public Carbon $graceEnd,
        public ?bool $pausedAtSend = null,
    ) {}

    /**
     * Train A2 (sweep-2 F-05): the episode clock at send time.
     * DunningService passes its runEpisode grace-end check in;
     * a directly built mail computes the same check from the
     * business and the grace end it carries. Composes with the
     * F-03 status branch: flowOff keys on status, this keys on
     * the clock, and a business is never both (paused requires
     * active). When paused, no surface of the mail may carry
     * the in-grace form or a grace date that is already past.
     */
    public function flowPaused(): bool
    {
        return $this->pausedAtSend
            ?? ($this->business->isActive() && now()->greaterThanOrEqualTo($this->graceEnd));
    }


    /**
     * Train A4 (OB-03, Amendment 45): the r43 text leg of
     * flowIsActive. An active business with no friend reward
     * text has its flow off for the text reason, in grace and
     * past it alike, so the keeps-working and paused forms are
     * both false for it; it gets the text-reason form instead.
     */
    public function flowTextless(): bool
    {
        return $this->business->isActive() && ! $this->business->hasFriendRewardText();
    }

    /**
     * Train A4 (OB-02, Amendment 45): whether a card is on
     * file at send time. The charger skips a cardless business,
     * so the retry tail may promise a retry only here.
     */
    public function hasCard(): bool
    {
        return \App\Models\PaymentMethod::defaultFor($this->business) !== null;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Easy Refer payment did not go through');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.dunning-failed', with: [
            'business' => $this->business,
            // F-03 / Amendment 36: a business that is not active
            // (closed or disabled) has its code already off and a
            // card update cannot restart it, so the template
            // branches to the off wording; a closed business also
            // loses the card action, which its middleware refuses.
            'flowOff' => ! $this->business->isActive(),
            'flowPaused' => $this->flowPaused(),
            'flowTextless' => $this->flowTextless(),
            'hasCard' => $this->hasCard(),
            'nextChargeDate' => $this->business->localTime(\App\Support\BillingForecast::nextChargeInstant())->format('M j, Y'),
            'canUpdateCard' => ! $this->business->isClosed() || $this->business->hasOutstandingBillingBalance(),
            'amount' => Money::usd($this->record->amount_cents),
            // Train 3.5: the grace end is an instant; the date
            // the owner reads is its business-local reading.
            'graceEndDate' => $this->business->localTime($this->graceEnd)->format('M j, Y'),
            'billingUrl' => route('settings.billing'),
        ]);
    }
}
