<?php

namespace Tests\Fakes;

use App\Contracts\PhoneVerificationGateway;

/**
 * A Verify stand-in for the suite (twilio-blueprint Section 2:
 * phpunit never calls the provider). The approved code defaults to
 * the classic test code; landline and failure behaviors are
 * injectable per test.
 */
class FakePhoneVerificationGateway implements PhoneVerificationGateway
{
    /** @var list<array{phone: string, channel: string}> */
    public array $starts = [];

    /** @var list<array{phone: string, code: string}> */
    public array $checks = [];

    public string $approvedCode = '123456';

    /** @var array<string, string> phone => start outcome override */
    public array $startOutcomes = [];

    public ?string $landlinePhone = null;

    /** When true, the landline also refuses the voice channel. */
    public bool $landlineBlocksCall = false;

    /** Override for the next checks (provider_error injection). */
    public ?string $checkOutcome = null;

    public function start(string $phoneE164, string $channel = 'sms'): array
    {
        $this->starts[] = ['phone' => $phoneE164, 'channel' => $channel];

        if (isset($this->startOutcomes[$phoneE164])) {
            return ['outcome' => $this->startOutcomes[$phoneE164], 'provider_status' => null];
        }

        if ($this->landlinePhone === $phoneE164 && ($channel === 'sms' || $this->landlineBlocksCall)) {
            return ['outcome' => 'landline_blocked', 'provider_status' => null];
        }

        return ['outcome' => 'started', 'provider_status' => 'pending'];
    }

    public function check(string $phoneE164, string $code): array
    {
        $this->checks[] = ['phone' => $phoneE164, 'code' => $code];

        if ($this->checkOutcome !== null) {
            return ['outcome' => $this->checkOutcome, 'provider_status' => null];
        }

        return $code === $this->approvedCode
            ? ['outcome' => 'approved', 'provider_status' => 'approved']
            : ['outcome' => 'pending', 'provider_status' => 'pending'];
    }
}
