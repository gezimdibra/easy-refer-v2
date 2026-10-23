<?php

namespace App\Jobs;

use App\Contracts\SmsGateway;
use App\Mail\FirstVisitAlertMail;
use App\Models\Business;
use App\Models\BusinessAlert;
use App\Models\SmsMessage;
use App\Models\SmsOptOut;
use App\Services\Referral\MessageCopy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * The first-visit owner alert (spec Section 3 Step 11b, growth
 * F2): an email always, plus an SMS only when the owner enabled
 * text notifications. The BusinessAlert row (unique per business
 * and kind) is claimed by the redemption transaction before this
 * job is dispatched, so retries and replays can never alert twice;
 * this job only delivers.
 */
class SendFirstVisitAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $businessId) {}

    public function handle(SmsGateway $sms): void
    {
        $business = Business::query()->with('owner')->find($this->businessId);
        $alert = BusinessAlert::query()
            ->where('business_id', $this->businessId)
            ->where('kind', 'first_visit')
            ->first();

        if ($business === null || $alert === null || $alert->notification_state === 'sent') {
            return;
        }

        $owner = $business->owner;

        if ($owner !== null) {
            Mail::to($owner->email)->send(new FirstVisitAlertMail($business));

            if ($business->owner_text_notifications && ! SmsOptOut::optedOut($owner->phone_e164)) {
                $body = MessageCopy::firstVisitAlert($business, route('dashboard'));

                $record = SmsMessage::create([
                    'business_id' => $business->id,
                    'phone_e164' => $owner->phone_e164,
                    'rendered_body' => $body,
            'purpose' => 'owner_alert',
                    'template_key' => 'first_visit_alert',
                    'delivery_state' => 'queued',
                            'consent_basis' => 'owner_setting',
                    'opt_out_state_at_send' => false,
                ]);

                $sid = $sms->send($owner->phone_e164, $body);
                $record->forceFill([
                    'twilio_message_sid' => $sid,
                    'delivery_state' => $sid === null ? 'failed' : 'sent',
                ])->save();
            }
        }

        $alert->forceFill(['notification_state' => 'sent'])->save();
    }
}
