<?php

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\TestCase;

/**
 * TOTP primitives (RFC 6238), pinned to the standard's own test
 * vectors so the implementation is verified against the spec of the
 * mechanism, not against itself.
 */
class TotpTest extends TestCase
{
    // The RFC 6238 SHA-1 seed: ASCII "12345678901234567890".
    private const RFC_SECRET_BASE32 = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function test_base32_round_trips(): void
    {
        $this->assertSame(self::RFC_SECRET_BASE32, Totp::base32Encode('12345678901234567890'));
        $this->assertSame('12345678901234567890', Totp::base32Decode(self::RFC_SECRET_BASE32));
        $this->assertSame('MY', Totp::base32Encode('f'));
        $this->assertSame('MZXW6', Totp::base32Encode('foo'));
        $this->assertSame('foo', Totp::base32Decode('MZXW6==='));
    }

    public function test_generated_secrets_are_160_bit_base32(): void
    {
        $secret = Totp::generateSecret();

        $this->assertSame(32, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        $this->assertNotSame($secret, Totp::generateSecret());
    }

    public function test_rfc_6238_sha1_vectors_at_six_digits(): void
    {
        // The RFC publishes 8-digit values; the six-digit code is the
        // same value modulo 10^6.
        $vectors = [
            59 => '287082',
            1111111109 => '081804',
            1111111111 => '050471',
            1234567890 => '005924',
            2000000000 => '279037',
            20000000000 => '353130',
        ];

        foreach ($vectors as $timestamp => $expected) {
            $this->assertSame($expected, Totp::code(self::RFC_SECRET_BASE32, $timestamp), "timestamp {$timestamp}");
        }
    }

    public function test_verify_accepts_the_current_and_neighbouring_periods_only(): void
    {
        $at = 1234567890;

        $this->assertTrue(Totp::verify(self::RFC_SECRET_BASE32, Totp::code(self::RFC_SECRET_BASE32, $at), $at));
        $this->assertTrue(Totp::verify(self::RFC_SECRET_BASE32, Totp::code(self::RFC_SECRET_BASE32, $at - 30), $at));
        $this->assertTrue(Totp::verify(self::RFC_SECRET_BASE32, Totp::code(self::RFC_SECRET_BASE32, $at + 30), $at));
        $this->assertFalse(Totp::verify(self::RFC_SECRET_BASE32, Totp::code(self::RFC_SECRET_BASE32, $at + 90), $at));
    }

    public function test_verify_rejects_malformed_codes(): void
    {
        $this->assertFalse(Totp::verify(self::RFC_SECRET_BASE32, '12345'));
        $this->assertFalse(Totp::verify(self::RFC_SECRET_BASE32, '1234567'));
        $this->assertFalse(Totp::verify(self::RFC_SECRET_BASE32, 'abcdef'));
        $this->assertFalse(Totp::verify(self::RFC_SECRET_BASE32, ''));
    }

    public function test_matching_timestep_identifies_the_period_a_code_belongs_to(): void
    {
        $at = 1234567890;
        $current = intdiv($at, Totp::PERIOD_SECONDS);

        $this->assertSame($current, Totp::matchingTimestep(self::RFC_SECRET_BASE32, Totp::code(self::RFC_SECRET_BASE32, $at), $at));
        $this->assertSame($current - 1, Totp::matchingTimestep(self::RFC_SECRET_BASE32, Totp::code(self::RFC_SECRET_BASE32, $at - 30), $at));
        $this->assertSame($current + 1, Totp::matchingTimestep(self::RFC_SECRET_BASE32, Totp::code(self::RFC_SECRET_BASE32, $at + 30), $at));
        $this->assertNull(Totp::matchingTimestep(self::RFC_SECRET_BASE32, Totp::code(self::RFC_SECRET_BASE32, $at + 90), $at));
        $this->assertNull(Totp::matchingTimestep(self::RFC_SECRET_BASE32, '12345', $at));
    }

    public function test_verify_tolerates_spaces_in_the_presented_code(): void
    {
        $at = 1234567890;
        $code = Totp::code(self::RFC_SECRET_BASE32, $at);

        $this->assertTrue(Totp::verify(self::RFC_SECRET_BASE32, substr($code, 0, 3).' '.substr($code, 3), $at));
    }

    public function test_base32_decode_skips_characters_outside_the_alphabet(): void
    {
        $this->assertSame('foo', Totp::base32Decode('MZ XW6'));
        $this->assertSame('foo', Totp::base32Decode('mzxw6'));
    }
}
