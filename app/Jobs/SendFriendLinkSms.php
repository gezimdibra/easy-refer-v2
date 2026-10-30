<?php

namespace App\Jobs;

use App\Contracts\SmsGateway;
use App\Models\Referral;
use App\Models\SmsMessage;
use App\Models\SmsOptOut;
use App\Services\Referral\MessageCopy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The optional friend link copy (spec Section 3 Step 8, SMS-03):
 * sent only when the friend consented at claim time. A carrier
 * failure records the truth on the message row and never wedges the
 * flow (EC-23, REF-35): the friend already has their page.
 */
class SendFriendLinkSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $referralId) {}

    public function handle(SmsGateway $sms): void
    {
        $referral = Referral::query()
            ->with(['business', 'referredCustomer', 'referrerMembership.customer'])
            ->find($this->referralId);

        if ($referral === null) {
            return;
        }

        // OB-06 (Amendment 45): the invite is re-checked at
        // handle time. A referral that has left pending
        // (redeemed, voided, expired) or is held for review no
        // longer has a claim to invite the friend to; the send
        // is skipped silently, as the job's other guards do.
        $referral->applyExpiry();
        if ($referral->state !== Referral::STATE_PENDING || $referral->hold) {
            return;
        }

        $friend = $referral->referredCustomer;
        $business = $referral->business;

        if (SmsOptOut::optedOut($friend->phone_e164)) {
            return;
        }

        $url = route('refer.redeem.page', ['token' => $referral->redeem_token_encrypted]);
        $body = MessageCopy::friendLink($business, $referral->referrerMembership->customer->first_name, $url);

        $record = SmsMessage::create([
            'business_id' => $business->id,
            'customer_id' => $friend->id,
            'phone_e164' => $friend->phone_e164,
            'rendered_body' => \App\Support\OpaqueToken::redact($body, (string) $referral->redeem_token_encrypted),
            'purpose' => 'friend_link',
            'template_key' => 'friend_link',
            'delivery_state' => 'queued',
            'consent_basis' => 'customer_checkbox',
            'opt_out_state_at_send' => false,
        ]);

        $sid = $sms->send($friend->phone_e164, $body);

        $record->forceFill([
            'twilio_message_sid' => $sid,
            'delivery_state' => $sid === null ? 'failed' : 'sent',
        ])->save();
    }
}
