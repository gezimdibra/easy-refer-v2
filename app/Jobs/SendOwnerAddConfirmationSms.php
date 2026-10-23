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
 * The one confirmation message an owner-added customer gets
 * (spec Section 5.2): it tells them they were added, and their
 * YES reply confirms the owner-asserted consent and delivers
 * their referral link (the inbound leg lives in the Twilio
 * webhook). Sent only when the owner asserted agreement; a
 * platform opt-out suppresses it entirely.
 */
class SendOwnerAddConfirmationSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $membershipId) {}

    public function handle(SmsGateway $sms): void
    {
        $membership = Membership::query()
            ->with(['business', 'customer'])
            ->find($this->membershipId);

        if ($membership === null || $membership->removed_at !== null) {
            return;
        }

        $customer = $membership->customer;
        $business = $membership->business;

        if (SmsOptOut::optedOut($customer->phone_e164)) {
            return;
        }

        $body = MessageCopy::ownerAddConfirmation($business);

        $record = SmsMessage::create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'phone_e164' => $customer->phone_e164,
            'rendered_body' => $body,
            'purpose' => 'owner_add_confirmation',
            'template_key' => 'owner_add_confirmation',
            'delivery_state' => 'queued',
            'consent_basis' => 'owner_asserted',
            'opt_out_state_at_send' => false,
        ]);

        $sid = $sms->send($customer->phone_e164, $body);

        $record->forceFill([
            'twilio_message_sid' => $sid,
            'delivery_state' => $sid === null ? 'failed' : 'sent',
        ])->save();
    }
}
