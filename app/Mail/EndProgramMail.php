<?php

namespace App\Mail;

use App\Models\Business;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * FE-CANCEL-01 (E-10): the end confirmation email. Exactly one
 * email is sent per ending, and it reports the final bill's
 * COMPLETE outcome: every leg class that occurred, each with
 * its own figure (charged / could-not-collect / written off).
 * A single-leg outcome renders that leg's original E-10 form
 * verbatim; a mixed outcome renders one line per leg, so no
 * figure ever stands in for the whole bill (Amendment 69).
 * While a final charge is still pending resolution nothing is
 * sent at all. The subject and every line are computed at send
 * time from the business's own facts.
 *
 * $outcome / $amountCents stay the PRIMARY leg (precedence:
 * failed, then paid, then written_off, then zero) — the form
 * name the audit ledger and the older pins cite. The three leg
 * figures are the complete outcome the body renders.
 */
class EndProgramMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Business $business,
        public string $outcome,
        public int $amountCents,
        public int $chargedCents = 0,
        public int $failedCents = 0,
        public int $writtenOffCents = 0,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Easy Refer program has ended');
    }

    public function content(): Content
    {
        $business = $this->business;
        $endedDate = $business->closed_at !== null
            ? $business->localTime($business->closed_at)->format('M j, Y')
            : $business->localTime(now())->format('M j, Y');
        $claimUntil = $business->closed_at !== null
            ? $business->localTime($business->closed_at->copy()->addDays(30))->format('M j, Y')
            : $business->localTime(now()->addDays(30))->format('M j, Y');

        return new Content(view: 'emails.end-program', with: [
            'business' => $business,
            'endedDate' => $endedDate,
            'claimUntil' => $claimUntil,
            'billLines' => $this->billLines(),
        ]);
    }

    /**
     * The bill section: one line per leg class present, each
     * naming only its own figure. A lone leg keeps its original
     * single-form E-10 sentence verbatim; no legs at all is the
     * zero form. A combined total is never rendered: it would
     * hide a leg.
     *
     * @return list<string>
     */
    public function billLines(): array
    {
        $charged = $this->chargedCents;
        $failed = $this->failedCents;
        $writtenOff = $this->writtenOffCents;

        // A construction that carries only the primary leg (the
        // pre-Amendment-69 shape) still renders that leg in full.
        if ($charged === 0 && $failed === 0 && $writtenOff === 0 && $this->amountCents > 0) {
            if ($this->outcome === 'paid') {
                $charged = $this->amountCents;
            } elseif ($this->outcome === 'failed') {
                $failed = $this->amountCents;
            } elseif ($this->outcome === 'written_off') {
                $writtenOff = $this->amountCents;
            }
        }

        $present = ($charged > 0 ? 1 : 0) + ($failed > 0 ? 1 : 0) + ($writtenOff > 0 ? 1 : 0);

        if ($present === 0) {
            return ['You owed nothing, so there is no final bill.'];
        }

        if ($present === 1) {
            return [match (true) {
                $charged > 0 => 'Your final bill of '.Money::usd($charged).' was charged to your card.',
                $failed > 0 => 'Your final bill of '.Money::usd($failed).' did not go through. Your program has still ended. We will try the card once a month, and you can update your card any time in Settings, under Billing.',
                default => 'Your final bill of '.Money::usd($writtenOff).' was under the $1 card minimum, so we did not charge it.',
            }];
        }

        $lines = [];

        if ($charged > 0) {
            $lines[] = 'We charged '.Money::usd($charged).' to your card.';
        }

        if ($failed > 0) {
            $lines[] = 'We could not collect '.Money::usd($failed).'. Your program has still ended. We will try the card once a month, and you can update your card any time in Settings, under Billing.';
        }

        if ($writtenOff > 0) {
            $lines[] = Money::usd($writtenOff).' was under the $1 card minimum, so we did not charge it.';
        }

        return $lines;
    }
}
