<?php

namespace Tests\Unit;

use App\Services\Demo\DemoVerificationGateway;
use Tests\TestCase;

/**
 * The demo verification gateway (Amendment 63, design pass
 * Section 4.2): per-session generated codes (never a fixed
 * value), counted attempts, resend invalidating the old code,
 * and restore for the per-request store.
 */
class DemoVerificationGatewayTest extends TestCase
{
    public function test_codes_are_generated_per_start_and_differ_across_sessions(): void
    {
        $gateway = new DemoVerificationGateway;

        $codes = [];
        for ($i = 0; $i < 12; $i++) {
            $gateway->start('+12125550147');
            $codes[] = $gateway->codeFor('+12125550147');
        }

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $code);
        }
        $this->assertNotSame(['123456'], array_unique($codes), 'The fixed factory code never appears as the demo code.');
        $this->assertGreaterThan(1, count(array_unique($codes)), 'Codes are generated, not fixed.');
    }

    public function test_check_approves_only_the_current_code_and_counts_attempts(): void
    {
        $gateway = new DemoVerificationGateway;
        $gateway->start('+12125550147');
        $code = $gateway->codeFor('+12125550147');
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->assertSame('pending', $gateway->check('+12125550147', $wrong)['outcome']);
        $this->assertSame(1, $gateway->checksFor('+12125550147'));
        $this->assertSame('approved', $gateway->check('+12125550147', $code)['outcome']);
        $this->assertNull($gateway->codeFor('+12125550147'), 'An approved code is spent.');
    }

    public function test_a_new_start_invalidates_the_old_code(): void
    {
        $gateway = new DemoVerificationGateway;
        $gateway->start('+12125550147');
        $first = $gateway->codeFor('+12125550147');

        do {
            $gateway->start('+12125550147');
            $second = $gateway->codeFor('+12125550147');
        } while ($second === $first);

        $this->assertSame('pending', $gateway->check('+12125550147', (string) $first)['outcome']);
        $this->assertSame('approved', $gateway->check('+12125550147', (string) $second)['outcome']);
    }

    public function test_restore_rehydrates_a_pending_code_for_a_later_request(): void
    {
        $gateway = new DemoVerificationGateway;
        $gateway->restore('+12125550147', '481516', 2);

        $this->assertSame('481516', $gateway->codeFor('+12125550147'));
        $this->assertSame(2, $gateway->checksFor('+12125550147'));
        $this->assertSame('approved', $gateway->check('+12125550147', '481516')['outcome']);
    }

    public function test_check_without_a_pending_code_is_denied(): void
    {
        $gateway = new DemoVerificationGateway;
        $this->assertSame('denied', $gateway->check('+12125550147', '000000')['outcome']);
    }
}
