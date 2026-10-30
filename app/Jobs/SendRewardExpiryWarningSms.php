<?php

namespace App\Jobs;

use App\Contracts\SmsGateway;
use App\Models\Reward;
use App\Models\SmsMessage;
use App\Models\SmsOptOut;
use App\Services\Referral\MessageCopy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The M-4 expiry warning text [M4-SMS-EXP-01] (design pass
 * Section 2.4): ONE transactional text per reward, sent inside
 * its last warning window of claimable time. It is about the
 * recipient's own reward state, the same basis as the
 * reward-ready text, so it is platform-sent and NEVER billed:
 * campaign billing reads only campaign-linked messages, and
 * this record carries no campaign link (proven by test: no
 * settlement or statement walk ever sees purpose
 * reward_expiry_warning).
 *
 * Send-time discipline mirrors SendRewardReadySms (FH-OB1):
 * every fact is re-checked inside the job, never trusted from
 * dispatch time. The marker column expiry_notice_sent_at is
 * stamped on a real send and on terminal suppression (the
 * reward can never become warnable), and left unstamped on
 * transient states (held, still vesting) and on a failed send
 * the queue will retry, so the one-text rule and the retry rule
 * can never both fire.
 */
class SendRewardExpiryWarningSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $rewardId) {}

    public function handle(SmsGateway $sms): void
    {
        $reward = Reward::query()->with(['business', 'membership.customer'])->find($this->rewardId);

        if ($reward === null || $reward->expiry_notice_sent_at !== null) {
            return;
        }

        $reward->applyClosureExpiry();
        $reward->applyTimeExpiry();
        $reward->applyVesting();

        // Terminal: the reward is gone or already used, so no
        // warning can ever become true. Stamp the marker so the
        // daily command never selects this row again.
        if (in_array($reward->state, [Reward::STATE_DENIED, Reward::STATE_EXPIRED, Reward::STATE_CLAIMED], true)) {
            $reward->forceFill(['expiry_notice_sent_at' => now()])->save();

            return;
        }

        // Transient: held (clock paused; the release top-up
        // guarantees a fresh window to warn in) or still
        // vesting. Nothing sends and the marker stays unstamped.
        if ($reward->hold || $reward->state !== Reward::STATE_AVAILABLE) {
            return;
        }

        // The window is re-checked at send: a date that moved
        // out of the warning window (a release top-up between
        // dispatch and send) waits for its own window.
        $warningDays = (int) config('referrals.reward_expiry_warning_days', 30);

        if ($reward->expires_at === null
            || $reward->expires_at->isPast()
            || $reward->expires_at->greaterThan(now()->addDays($warningDays))) {
            return;
        }

        $customer = $reward->membership->customer;
        $business = $reward->business;

        if (SmsOptOut::optedOut($customer->phone_e164)) {
            $reward->forceFill(['expiry_notice_sent_at' => now()])->save();

            return;
        }

        $claimUrl = route('refer.claim.page', ['token' => $reward->claim_token_encrypted]);
        $date = $business->localTime($reward->expires_at)->format('M j, Y');
        $body = MessageCopy::rewardExpiryWarning($business, $reward->reward_text_snapshot, $date, $claimUrl);

        $record = SmsMessage::create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'phone_e164' => $customer->phone_e164,
            'rendered_body' => \App\Support\OpaqueToken::redact($body, (string) $reward->claim_token_encrypted),
            'purpose' => 'reward_expiry_warning',
            'template_key' => 'reward_expiry_warning',
            'delivery_state' => 'queued',
            'consent_basis' => 'transactional',
            'opt_out_state_at_send' => false,
        ]);

        $sid = $sms->send($customer->phone_e164, $body);

        if ($sid === null) {
            $record->forceFill(['delivery_state' => 'failed'])->save();

            if ($this->attempts() < $this->tries) {
                $this->release(60 * $this->attempts());
            }

            return;
        }

        $record->forceFill(['twilio_message_sid' => $sid, 'delivery_state' => 'sent'])->save();
        $reward->forceFill(['expiry_notice_sent_at' => now()])->save();
    }
}
