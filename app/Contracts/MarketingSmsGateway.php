<?php

namespace App\Contracts;

/**
 * Marketing SMS delivery (spec Section 10.3): campaign sends
 * ride the marketing messaging service only, never the
 * transactional pool. This is a separate contract from
 * SmsGateway so the two pools can never be crossed by a
 * type-hint mistake; the shapes are deliberately identical.
 */
interface MarketingSmsGateway
{
    /**
     * Send one message. Returns the provider message SID, or null
     * when the send failed; failures are logged by category only,
     * never with the message body.
     */
    public function send(string $toPhoneE164, string $body): ?string;

    /**
     * Segment count for a body, per the GSM-7 / UCS-2 rules the
     * provider bills on (the campaign metering input).
     */
    public function segmentCount(string $body): int;
}
