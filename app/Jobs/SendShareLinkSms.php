<?php

namespace App\Jobs;

use App\Contracts\SmsGateway;
use App\Models\Membership;
use App\Models\SmsMessage;
use App\Models\SmsOptOut;
use App\Services\Referral\MessageCopy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The referral link delivery text (CUST-11): sent when an
 * owner-added customer confirms by replying YES (spec Section
 * 5.2), carrying the same sealed share link the enrollment flow
 * shows on screen.
 */
class SendShareLinkSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $membershipId) {}

    public function handle(SmsGateway $sms): void
    {
        $membership = Membership::query()
            ->with(['business', 'customer'])
            ->find($this->membershipId);

        if ($membership === null || $membership->removed_at !== null || $membership->plainShareToken() === null) {
            return;
        }

        $customer = $membership->customer;
        $business = $membership->business;

        if (SmsOptOut::optedOut($customer->phone_e164)) {
            return;
        }

        $url = route('refer.friend', ['token' => $membership->plainShareToken()]);
        // OB-05 (Amendment 45): the earning promise is checked
        // against the flow at handle time; while the flow is
        // inactive the link still goes out, without it.
        $body = $business->flowIsActive()
            ? MessageCopy::shareLink($business, $url)
            : MessageCopy::shareLinkFlowInactive($business, $url);

        $record = SmsMessage::create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'phone_e164' => $customer->phone_e164,
            'rendered_body' => \App\Support\OpaqueToken::redact($body, $membership->plainShareToken()),
            'purpose' => 'share_link',
            'template_key' => 'share_link',
            'delivery_state' => 'queued',
            'consent_basis' => 'customer_reply',
            'opt_out_state_at_send' => false,
        ]);

        $sid = $sms->send($customer->phone_e164, $body);

        $record->forceFill([
            'twilio_message_sid' => $sid,
            'delivery_state' => $sid === null ? 'failed' : 'sent',
        ])->save();
    }
}
