<?php

namespace App\Contracts;

/**
 * Transactional SMS delivery (spec Section 10.1). Sends ride the
 * transactional messaging service only; the marketing pool is a
 * separate configuration the campaign sender uses. Every send is
 * preceded by the opt-out check in the calling service, and the
 * pipeline re-checks before handing off (spec Section 10.2).
 */
interface SmsGateway
{
    /**
     * Send one message. Returns the provider message SID, or null
     * when the send failed; failures are logged by category only,
     * never with the message body.
     */
    public function send(string $toPhoneE164, string $body): ?string;

    /**
     * Segment count for a body, per the GSM-7 / UCS-2 rules the
     * provider bills on (drives the Section 7.4 bulk metering and
     * the two-segment transactional length guard).
     */
    public function segmentCount(string $body): int;
}
