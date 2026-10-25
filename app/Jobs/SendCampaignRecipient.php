<?php

namespace App\Jobs;

use App\Contracts\MarketingSmsGateway;
use App\Models\SmsCampaignRecipient;
use App\Models\SmsMessage;
use App\Models\SmsOptOut;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Campaigns\CampaignSender;
use App\Services\Referral\MessageCopy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One campaign recipient's send (spec Sections 10.3, 10.4).
 * Modeled on the shipped send jobs: the eligibility facts are
 * re-checked at the moment of the send (threat G-14: a STOP
 * landing mid-campaign stops the rest of that campaign to that
 * number), the sms_messages evidence row is written before the
 * provider call, and the job's own tries are the retry policy.
 * A recipient whose send was attempted carries its evidence
 * link, which is what the monthly charger bills on.
 */
class SendCampaignRecipient implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $recipientId) {}

    public function handle(MarketingSmsGateway $sms, CampaignSender $sender): void
    {
        $recipient = SmsCampaignRecipient::query()
            ->with(['campaign.business', 'membership.customer'])
            ->find($this->recipientId);

        if ($recipient === null || $recipient->delivery_state !== SmsCampaignRecipient::STATE_QUEUED) {
            return;
        }

        $campaign = $recipient->campaign;
        $membership = $recipient->membership;
        $phone = $recipient->phone_e164;

        // The G-14 re-check, in precedence order: platform
        // opt-out, membership removal, consent state.
        if (SmsOptOut::optedOut($phone)) {
            $this->skip($recipient, SmsCampaignRecipient::SKIP_OPTED_OUT, $sender);

            return;
        }

        if ($membership === null || $membership->removed_at !== null) {
            $this->skip($recipient, SmsCampaignRecipient::SKIP_MEMBERSHIP_REMOVED, $sender);

            return;
        }

        if (! $membership->sms_consent
            || ! in_array($membership->consent_source, CampaignAudience::CUSTOMER_SOURCES, true)) {
            $this->skip($recipient, SmsCampaignRecipient::SKIP_CONSENT_REVOKED, $sender);

            return;
        }

        $body = MessageCopy::campaign($campaign->business, $campaign->body);

        // The evidence row first (a retry reuses it, so one
        // recipient has exactly one evidence row), then the
        // provider call.
        $message = $recipient->smsMessage ?? SmsMessage::create([
            'business_id' => $campaign->business_id,
            'customer_id' => $recipient->customer_id,
            'phone_e164' => $phone,
            'purpose' => 'campaign',
            'messaging_service' => 'marketing',
            'template_key' => 'campaign',
            'rendered_body' => $body,
            'delivery_state' => 'queued',
            'consent_basis' => $membership->consent_source,
            'opt_out_state_at_send' => false,
        ]);

        if ($recipient->sms_message_id === null) {
            $recipient->forceFill(['sms_message_id' => $message->id])->save();
        }

        $sid = $sms->send($phone, $body);

        if ($sid === null) {
            $message->forceFill(['delivery_state' => 'failed'])->save();

            if ($this->attempts() < $this->tries) {
                $this->release(60 * $this->attempts());

                return;
            }

            $recipient->forceFill(['delivery_state' => SmsCampaignRecipient::STATE_FAILED])->save();
            $sender->finalizeIfComplete($campaign);

            return;
        }

        $message->forceFill(['twilio_message_sid' => $sid, 'delivery_state' => 'sent'])->save();
        $recipient->forceFill([
            'twilio_message_sid' => $sid,
            'delivery_state' => SmsCampaignRecipient::STATE_SENT,
        ])->save();

        $sender->finalizeIfComplete($campaign);
    }

    private function skip(SmsCampaignRecipient $recipient, string $reason, CampaignSender $sender): void
    {
        $recipient->forceFill([
            'delivery_state' => SmsCampaignRecipient::STATE_SKIPPED,
            'skip_reason' => $reason,
        ])->save();

        $sender->finalizeIfComplete($recipient->campaign);
    }
}
