<?php

namespace App\Mail;

use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * A2, the allowance-spent code-paused warning, plus its one
 * day-7 reminder form (M-5 design pass Sections 4.5 and 6.5;
 * copy-inventory Amendment 72, [M5-A2-*] and [M5-A2R-*]).
 * Sent by LifecycleMailService only while
 * Business::flowBlockReason() is 'allowance_exhausted' at
 * send time and the business is not past due. Money-class:
 * a paused code is a billing state, so this mail sits
 * outside the drip-class ceiling (pass Section 7.2).
 *
 * The reminder form switches subject and body on the
 * $reminder flag and on the first send's age: "A week ago"
 * under 10 days, "Over a week ago" from day 10 (both forms
 * inventoried in [M5-A2R-B]; no third form exists). The
 * reminder names no new fact, deadline, or consequence.
 *
 * Deliberately NOT ShouldQueue: renders at send time from
 * live facts (pass Section 4.1).
 */
class AllowancePausedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Business $business,
        public bool $reminder = false,
        public ?Carbon $firstSentAt = null,
    ) {}

    private function money(int $cents): string
    {
        return $cents % 100 === 0
            ? '$'.intdiv($cents, 100)
            : '$'.number_format($cents / 100, 2);
    }

    private function subjectLine(): string
    {
        return $this->reminder
            ? 'Still paused: your Easy Refer code'
            : 'Your Easy Refer code has paused';
    }

    private function headline(): string
    {
        return 'Your '.(int) config('billing.free_visits_at_signup', 5)
            .' free visits are used. A card turns your code back on';
    }

    private function body(): string
    {
        $free = (int) config('billing.free_visits_at_signup', 5);

        if ($this->reminder) {
            $ago = $this->firstSentAt !== null && $this->firstSentAt->copy()->addDays(10)->lte(now())
                ? 'Over a week ago'
                : 'A week ago';

            return $ago.' we wrote that your code for '.$this->business->name
                .' had paused, because your '.$free.' free visits are used and there is '
                .'no card on file yet. It is still paused. Add a card and it turns back '
                .'on right away. Rewards your customers already earned still work as usual.';
        }

        return 'Referred friends have now visited '.$this->business->name.' '
            .$this->business->allowanceUsed().' times, and your first '.$free
            .' visits were free, as promised. Because there is no card on file yet, '
            .'your code has paused: new customers who scan it will see that it is paused. '
            .'Rewards your customers already earned still work as usual. Add a card and '
            .'your code turns back on right away. From then on it is '
            .$this->money((int) config('billing.price_per_verified_visit_cents', 200))
            .' when a referred friend visits, charged once a month.';
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function content(): Content
    {
        return new Content(view: 'emails.allowance-paused', with: [
            'business' => $this->business,
            'mailTitle' => $this->subjectLine(),
            'headline' => $this->headline(),
            'body' => $this->body(),
            'reminder' => $this->reminder,
            'visitsUsed' => $this->business->allowanceUsed(),
            'actionUrl' => route('settings.billing'),
        ]);
    }
}
