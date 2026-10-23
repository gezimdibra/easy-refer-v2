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
 * The referrer reward-ready SMS (spec Section 3 Step 11), sent
 * after the redemption transaction commits. The reward's
 * notification_state is the record: queued, sent, failed (retried
 * by the queue, never silently dropped, EC-23), or suppressed by a
 * platform opt-out (SMS-05). A send that keeps failing stays
 * visible as failed on the reward row after the final attempt.
 */
class SendRewardReadySms implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $rewardId) {}

    public function handle(SmsGateway $sms): void
    {
        $reward = Reward::query()->with(['business', 'membership.customer'])->find($this->rewardId);

        if ($reward === null || $reward->notification_state === 'sent') {
            return;
        }

        $customer = $reward->membership->customer;
        $business = $reward->business;

        if (SmsOptOut::optedOut($customer->phone_e164)) {
            $reward->forceFill(['notification_state' => 'suppressed'])->save();

            return;
        }

        $claimUrl = route('refer.claim.page', ['token' => $reward->claim_token_encrypted]);
        $body = MessageCopy::rewardReady($business, $reward->reward_text_snapshot, $claimUrl);

        $record = SmsMessage::create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'phone_e164' => $customer->phone_e164,
            'rendered_body' => \App\Support\OpaqueToken::redact($body, (string) $reward->claim_token_encrypted),
            'purpose' => 'reward_ready',
            'template_key' => 'reward_ready',
            'delivery_state' => 'queued',
            'consent_basis' => 'transactional',
            'opt_out_state_at_send' => false,
        ]);

        $sid = $sms->send($customer->phone_e164, $body);

        if ($sid === null) {
            $record->forceFill(['delivery_state' => 'failed'])->save();
            $reward->forceFill(['notification_state' => 'failed'])->save();

            if ($this->attempts() < $this->tries) {
                $this->release(60 * $this->attempts());
            }

            return;
        }

        $record->forceFill(['twilio_message_sid' => $sid, 'delivery_state' => 'sent'])->save();
        $reward->forceFill(['notification_state' => 'sent'])->save();
    }
}
