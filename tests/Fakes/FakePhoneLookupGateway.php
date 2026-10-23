<?php

namespace Tests\Fakes;

use App\Contracts\PhoneLookupGateway;

/**
 * Lookup stand-in: neutral by default; a test marks a phone as
 * recently SIM-swapped to exercise the EC-16 step-up.
 */
class FakePhoneLookupGateway implements PhoneLookupGateway
{
    /** @var list<string> */
    public array $lookups = [];

    /** @var list<string> */
    public array $recentSimSwapPhones = [];

    public function lookup(string $phoneE164): array
    {
        $this->lookups[] = $phoneE164;

        return [
            'sim_changed_recently' => in_array($phoneE164, $this->recentSimSwapPhones, true),
            'line_type' => 'mobile',
            'carrier' => 'Test Carrier',
        ];
    }
}
