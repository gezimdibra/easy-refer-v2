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
 * Dunning day 3 (spec Section 9.6; inventory DUN-03 subject):
 * the reminder inside the grace window. The inventory carries
 * no separate day 3 body, so the body is DUN-02 again, the
 * same episode facts the day 0 email stated (the failed
 * record's amount, the grace end, the billing link); nothing
 * is invented for this send.
 */
class DunningReminderMail extends Mailable
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
        // F-03 / Amendment 36: the dated deadline subject is
        // valid only while the business is active; a closed or
        // disabled business is told the still-due truth instead.
        return new Envelope(subject: (! $this->business->isActive() || $this->flowPaused() || $this->flowTextless())
            ? 'Reminder: your Easy Refer payment is still due'
            : 'Reminder: update your card by '.$this->business->localTime($this->graceEnd)->format('M j, Y'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.dunning-reminder', with: [
            'business' => $this->business,
            'flowOff' => ! $this->business->isActive(),
            'flowPaused' => $this->flowPaused(),
            'flowTextless' => $this->flowTextless(),
            'hasCard' => $this->hasCard(),
            'nextChargeDate' => $this->business->localTime(\App\Support\BillingForecast::nextChargeInstant())->format('M j, Y'),
            'canUpdateCard' => ! $this->business->isClosed() || $this->business->hasOutstandingBillingBalance(),
            'amount' => Money::usd($this->record->amount_cents),
            'graceEndDate' => $this->business->localTime($this->graceEnd)->format('M j, Y'),
            'billingUrl' => route('settings.billing'),
        ]);
    }
}
