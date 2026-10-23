<?php

namespace App\Services\Twilio;

use App\Contracts\PhoneLookupGateway;
use Illuminate\Support\Facades\Log;
use Twilio\Exceptions\TwilioException;
use Twilio\Http\Client as HttpClient;
use Twilio\Rest\Client;

/**
 * The real Twilio Lookup gateway (twilio-blueprint Section 2): the
 * SIM-swap and line-type packages, used only as a step-up risk
 * signal inside the number-link cooling window (EC-16). A swap
 * inside the last 30 days counts as recent. Provider failure reads
 * as neutral signals: an outage never punishes a customer.
 */
class TwilioPhoneLookupGateway implements PhoneLookupGateway
{
    public function __construct(private ?HttpClient $httpClient = null) {}

    public function lookup(string $phoneE164): array
    {
        try {
            $result = $this->client()->lookups->v2
                ->phoneNumbers($phoneE164)
                ->fetch(['fields' => 'sim_swap,line_type_intelligence']);

            $simSwap = is_array($result->simSwap) ? $result->simSwap : [];
            $lastSwap = $simSwap['last_sim_swap']['date'] ?? null;
            $lineType = is_array($result->lineTypeIntelligence) ? $result->lineTypeIntelligence : [];

            return [
                'sim_changed_recently' => $lastSwap !== null
                    && now()->subDays(30)->lessThan(\Illuminate\Support\Carbon::parse((string) $lastSwap)),
                'line_type' => $lineType['line_type'] ?? null,
                'carrier' => $lineType['carrier_name'] ?? null,
            ];
        } catch (TwilioException $e) {
            Log::warning('Twilio Lookup failed; signals read as neutral.', ['provider_code' => $e->getCode()]);

            return ['sim_changed_recently' => false, 'line_type' => null, 'carrier' => null];
        }
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
