<?php

namespace App\Services\Twilio;

use App\Contracts\PhoneVerificationGateway;
use Illuminate\Support\Facades\Log;
use Twilio\Exceptions\TwilioException;
use Twilio\Http\Client as HttpClient;
use Twilio\Rest\Client;

/**
 * The real Twilio Verify gateway (spec Section 10, twilio-blueprint
 * Section 1). Outbound auth is the account's API key (Section 10:
 * API key auth, never the account auth token). The provider owns
 * the code lifecycle: this class only starts checks and reads
 * outcomes.
 *
 * Outcome mapping follows the blueprint: a landline destination on
 * the SMS channel is its own outcome (EC-19 copies it, and the
 * caller offers the call channel); the provider's own send cap is
 * rate_limited; anything else unexpected is provider_error, and the
 * caller shows the generic unavailable copy. Failures log the
 * provider error code only, never a phone number or a code.
 *
 * The HTTP transport is injectable (the Twilio client's PSR-style
 * seam): tests drive every branch against canned provider
 * responses; the live sandbox pass is the staging smoke test the
 * blueprint prescribes, never phpunit.
 */
class TwilioPhoneVerificationGateway implements PhoneVerificationGateway
{
    public function __construct(private ?HttpClient $httpClient = null) {}

    public function start(string $phoneE164, string $channel = 'sms'): array
    {
        try {
            $verification = $this->client()->verify->v2
                ->services((string) config('services.twilio.verify_service_sid'))
                ->verifications
                ->create($phoneE164, $channel);

            return ['outcome' => 'started', 'provider_status' => $verification->status];
        } catch (TwilioException $e) {
            return $this->mapStartError($e);
        }
    }

    public function check(string $phoneE164, string $code): array
    {
        try {
            $check = $this->client()->verify->v2
                ->services((string) config('services.twilio.verify_service_sid'))
                ->verificationChecks
                ->create(['to' => $phoneE164, 'code' => $code]);

            return [
                'outcome' => $check->status === 'approved' ? 'approved' : 'pending',
                'provider_status' => $check->status,
            ];
        } catch (TwilioException $e) {
            Log::warning('Twilio Verify check failed.', ['provider_code' => $e->getCode()]);

            // An expired or consumed verification (20404) is, to the
            // customer, the same event as a wrong code: the generic
            // denial. Nothing here may distinguish them (SMS-08).
            return ['outcome' => 'denied', 'provider_status' => null];
        }
    }

    /**
     * @return array{outcome: string, provider_status: ?string}
     */
    private function mapStartError(TwilioException $e): array
    {
        Log::warning('Twilio Verify start failed.', ['provider_code' => $e->getCode()]);

        return match ($e->getCode()) {
            // The destination cannot receive texts (landline).
            60205 => ['outcome' => 'landline_blocked', 'provider_status' => null],
            // The provider's own send-attempt ceiling for the number.
            60203 => ['outcome' => 'rate_limited', 'provider_status' => null],
            default => ['outcome' => 'provider_error', 'provider_status' => null],
        };
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
