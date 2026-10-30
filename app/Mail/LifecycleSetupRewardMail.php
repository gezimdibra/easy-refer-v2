<?php

namespace App\Mail;

use App\Models\Business;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * D1, the reward-stall drip email (M-5 design pass Sections
 * 3.3 and 6.1; copy-inventory Amendment 72, [M5-D1-*]).
 * Sent by LifecycleMailService exactly once per business,
 * only while the reward is still unset at send time.
 *
 * Deliberately NOT ShouldQueue: the body must render at send
 * time from live facts (the OB-04 queue-freeze lesson the
 * pass's Section 4.1 protocol carries). The headline is the
 * pass's fallback form [M5-D1-H2]: it is true in every state
 * the eligibility predicate admits, while [M5-D1-H] asserts
 * the reward is the one blocker, which the allowance-exhausted
 * corner (reward missing AND allowance spent) would falsify.
 * No steps-left count prints: the pass permits shipping D1
 * with no count, and no count means no second counter exists
 * to diverge from WelcomeMail's shared derivation.
 */
class LifecycleSetupRewardMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Business $business,
        public User $owner,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Set your reward, and your code can work');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.lifecycle-setup-reward', with: [
            'business' => $this->business,
            'mailTitle' => 'Set your reward, and your code can work',
            'firstName' => Str::before($this->owner->name, ' '),
            'actionUrl' => route('onboarding.step', ['step' => 'rewards']),
        ]);
    }
}
