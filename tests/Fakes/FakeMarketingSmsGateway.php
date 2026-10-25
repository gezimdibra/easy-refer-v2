<?php

namespace Tests\Fakes;

use App\Contracts\MarketingSmsGateway;
use App\Support\SmsSegments;

/**
 * The marketing twin of FakeSmsGateway: records every send
 * instead of delivering. A phone in $failFor simulates a carrier
 * rejection (the send returns null, exactly the real gateway's
 * failure shape).
 */
class FakeMarketingSmsGateway implements MarketingSmsGateway
{
    /** @var list<array{to: string, body: string, sid: string}> */
    public array $sent = [];

    /** @var list<string> */
    public array $failFor = [];

    public function send(string $toPhoneE164, string $body): ?string
    {
        if (in_array($toPhoneE164, $this->failFor, true)) {
            return null;
        }

        $sid = 'SM_FAKE_MKT_'.count($this->sent);
        $this->sent[] = ['to' => $toPhoneE164, 'body' => $body, 'sid' => $sid];

        return $sid;
    }

    public function segmentCount(string $body): int
    {
        return SmsSegments::count($body);
    }
}
