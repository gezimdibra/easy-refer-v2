<?php

namespace App\Http\Controllers;

use App\Models\ConsentEvent;
use App\Models\Membership;
use App\Models\SmsMessage;
use App\Models\SmsOptOut;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Twilio\Security\RequestValidator;

/**
 * The Twilio status and inbound webhook (spec Section 7.4 and
 * inventory SMS-16 and SMS-17). Every request is verified against
 * Twilio's signature with the account auth token; without a
 * configured token nothing is accepted at all. A forged or
 * unsigned request is rejected and logged, and changes nothing.
 * Inbound STOP (and its synonyms) writes the platform opt-out, the
 * consent projection flips, and future sends are suppressed; the
 * transactional leg still applies, meaning a customer who opts out
 * cannot be sent marketing or reminder texts afterwards either.
 */
class TwilioWebhookController extends Controller
{
    private const STOP_WORDS = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'];

    public function __invoke(Request $request): Response
    {
        $authToken = (string) config('services.twilio.webhook_auth_token');

        if ($authToken === '') {
            return response('Forbidden', 403);
        }

        $signature = (string) $request->header('X-Twilio-Signature', '');
        $validator = new RequestValidator($authToken);
        $signedUrl = (string) (config('services.twilio.webhook_url') ?: $request->fullUrl());

        if (! $validator->validate($signature, $signedUrl, $request->post())) {
            Log::warning('Twilio webhook signature rejected', [
                'message_sid' => $request->input('MessageSid'),
            ]);

            return response('Forbidden', 403);
        }

        $sid = (string) $request->input('MessageSid', '');
        $status = (string) $request->input('MessageStatus', $request->input('SmsStatus', ''));
        // Keyword matching tolerates case, stray whitespace, and
        // trailing punctuation ("STOP."), the shapes real replies
        // arrive in; the carrier layer remains the opt-out of
        // record and this platform record mirrors it (EC-22).
        $body = rtrim(strtoupper((string) preg_replace('/\s+/', ' ', trim((string) $request->input('Body', '')))), '.!');
        $from = (string) $request->input('From', '');

        if ($sid !== '' && $status !== '') {
            $message = SmsMessage::query()->where('twilio_message_sid', $sid)->first();

            if ($message !== null) {
                $state = match ($status) {
                    'delivered' => 'delivered',
                    'undelivered', 'failed' => 'failed',
                    default => $message->delivery_state,
                };

                if ($state !== $message->delivery_state) {
                    $message->forceFill(['delivery_state' => $state])->save();
                }
            }
        }

        if ($body !== '' && $from !== '' && in_array($body, self::STOP_WORDS, true)) {
            SmsOptOut::query()->firstOrCreate(
                ['phone_e164' => $from],
                ['opted_out_at' => now(), 'source' => 'carrier_stop']
            );

            $customer = \App\Models\Customer::query()->where('phone_e164', $from)->first();

            if ($customer !== null) {
                foreach (Membership::query()->where('customer_id', $customer->id)->where('sms_consent', true)->get() as $membership) {
                    $membership->forceFill([
                        'sms_consent' => false,
                        'consent_source' => 'customer_reply',
                        'consent_updated_at' => now(),
                    ])->save();

                    ConsentEvent::create([
                        'membership_id' => $membership->id,
                        'customer_id' => $membership->customer_id,
                        'business_id' => $membership->business_id,
                        'event_type' => 'revoked',
                        'source' => 'customer_reply',
                        'consent_wording_version' => (string) config('referrals.consent_wording_version'),
                        'flow' => 'campaign_reply',
                        'ip_address' => $request->ip(),
                    ]);
                }
            }
        }

        return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200, [
            'Content-Type' => 'text/xml',
        ]);
    }
}
