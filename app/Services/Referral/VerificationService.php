<?php

namespace App\Services\Referral;

use App\Contracts\PhoneVerificationGateway;
use App\Models\Business;
use App\Services\Security\RateBudgetService;

/**
 * The app-side verification budgets (spec Sections 3 Step 4 and
 * 10.4), checked BEFORE the provider call so a call past budget
 * never reaches Twilio (REF-12). Budgets are database counters
 * keyed by phone hash, IP, and business; never by session (SMS-07).
 */
class VerificationService
{
    public function __construct(
        private PhoneVerificationGateway $gateway,
        private RateBudgetService $budgets,
    ) {}

    /**
     * Whether one more start fits every budget for this attempt.
     *
     * @return 'ok'|'phone'|'ip'|'business'
     */
    public function budgetState(string $phoneE164, string $ipAddress, Business $business): string
    {
        $limits = (array) config('referrals.verify');

        if ($this->budgets->count($this->phoneKey($phoneE164)) >= (int) $limits['starts_per_phone_per_hour']) {
            return 'phone';
        }

        if ($this->budgets->count($this->ipKey($ipAddress)) >= (int) $limits['starts_per_ip_per_hour']) {
            return 'ip';
        }

        if ($this->budgets->count($this->businessKey($business)) >= (int) $limits['starts_per_business_per_hour']) {
            return 'business';
        }

        return 'ok';
    }

    /**
     * Start a verification, consuming budget first. A spent budget
     * is reported as rate_limited without touching the provider.
     *
     * @return array{outcome: string, provider_status: ?string}
     */
    public function start(string $phoneE164, string $ipAddress, Business $business, string $channel = 'sms'): array
    {
        if ($this->budgetState($phoneE164, $ipAddress, $business) !== 'ok') {
            return ['outcome' => 'rate_limited', 'provider_status' => null];
        }

        $this->budgets->hit($this->phoneKey($phoneE164));
        $this->budgets->hit($this->ipKey($ipAddress));
        $this->budgets->hit($this->businessKey($business));

        return $this->gateway->start($phoneE164, $channel);
    }

    /**
     * @return array{outcome: string, provider_status: ?string}
     */
    public function check(string $phoneE164, string $code): array
    {
        return $this->gateway->check($phoneE164, $code);
    }

    private function phoneKey(string $phoneE164): string
    {
        return RateBudgetService::keyFor('verify_phone', hash('sha256', $phoneE164));
    }

    private function ipKey(string $ipAddress): string
    {
        return RateBudgetService::keyFor('verify_ip', hash('sha256', $ipAddress));
    }

    private function businessKey(Business $business): string
    {
        return RateBudgetService::keyFor('verify_business', (string) $business->id);
    }
}
