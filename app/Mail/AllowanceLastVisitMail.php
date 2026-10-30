<?php

namespace App\Mail;

use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A1, the one-free-visit-left warning (M-5 design pass
 * Sections 4.5 and 6.4; copy-inventory Amendment 72,
 * [M5-A1-*]). Sent by LifecycleMailService exactly once per
 * business, only in the state it names: no card on file,
 * Business::allowanceLeft() exactly 1 (the settlement walk,
 * never the free_visits_remaining column), the flow live,
 * and the business not past due.
 *
 * The plan figures in the body (the free-visit count, the
 * per-visit price, the monthly cap) render from config, as
 * everywhere in the tree, so the rendered string is the
 * inventoried string in the shipped configuration.
 *
 * Deliberately NOT ShouldQueue: renders at send time from
 * live facts (pass Section 4.1).
 */
class AllowanceLastVisitMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Business $business) {}

    /** A config money figure as the copy prints it: $2, $99. */
    private function money(int $cents): string
    {
        return $cents % 100 === 0
            ? '$'.intdiv($cents, 100)
            : '$'.number_format($cents / 100, 2);
    }

    private function subjectLine(): string
    {
        return 'One of your '.(int) config('billing.free_visits_at_signup', 5).' free visits is left';
    }

    private function body(): string
    {
        $free = (int) config('billing.free_visits_at_signup', 5);

        return 'Good news first: referred friends have visited '.$this->business->name.' '
            .$this->business->allowanceUsed().' times, and those visits were free. '
            .'One free visit is left. When one more referred friend visits, your code pauses '
            .'until you add a card. Add a card now and nothing pauses: visits after your free '
            .'ones are '.$this->money((int) config('billing.price_per_verified_visit_cents', 200))
            .' each, charged once a month, and never more than '
            .$this->money((int) config('billing.monthly_cap_cents', 9900))
            .' in a month in visit charges.';
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function content(): Content
    {
        return new Content(view: 'emails.allowance-last', with: [
            'business' => $this->business,
            'mailTitle' => $this->subjectLine(),
            'body' => $this->body(),
            'visitsUsed' => $this->business->allowanceUsed(),
            'visitsLeft' => $this->business->allowanceLeft(),
            'actionUrl' => route('settings.billing'),
        ]);
    }
}
