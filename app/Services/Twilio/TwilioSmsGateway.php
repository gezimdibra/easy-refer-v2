<?php

namespace App\Services\Twilio;

use App\Contracts\SmsGateway;
use App\Support\SmsSegments;
use Illuminate\Support\Facades\Log;
use Twilio\Exceptions\TwilioException;
use Twilio\Http\Client as HttpClient;
use Twilio\Rest\Client;

/**
 * The real transactional SMS gateway (spec Section 10.1). Sends
 * ride the transactional messaging service only, with the status
 * callback pointed at the signature-validated webhook. A failed
 * send returns null and logs the provider code only; the message
 * body and the destination never reach a log line (Section 8.8).
 */
class TwilioSmsGateway implements SmsGateway
{
    public function __construct(private ?HttpClient $httpClient = null) {}

    public function send(string $toPhoneE164, string $body): ?string
    {
        try {
            $message = $this->client()->messages->create($toPhoneE164, [
                'messagingServiceSid' => (string) config('services.twilio.messaging_service_sid'),
                'body' => $body,
                'statusCallback' => route('webhooks.twilio.status'),
            ]);

            return $message->sid;
        } catch (TwilioException $e) {
            Log::warning('Transactional SMS send failed.', ['provider_code' => $e->getCode()]);

            return null;
        }
    }

    public function segmentCount(string $body): int
    {
        return SmsSegments::count($body);
    }

    private function client(): Client
    {
        return new Client(
            (string) config('services.twilio.api_key_sid'),
            (string) config('services.twilio.api_key_secret'),
            (string) config('services.twilio.account_sid'),
            null,
            $this->httpClient,
        );
    }
}
