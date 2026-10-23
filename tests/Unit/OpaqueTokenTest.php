<?php

namespace Tests\Unit;

use App\Support\OpaqueToken;
use PHPUnit\Framework\TestCase;

/**
 * Spec Section 7.1 and protocol Section 2.1 (code generation): format,
 * entropy, uniqueness at volume, and hash-only storage behavior.
 */
class OpaqueTokenTest extends TestCase
{
    public function test_generated_tokens_are_url_safe_with_at_least_128_bits(): void
    {
        $token = OpaqueToken::generate();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
        $this->assertGreaterThanOrEqual(22, strlen($token), '128 bits base64url-encodes to at least 22 characters');
        $this->assertSame(43, strlen($token), '256-bit tokens encode to 43 characters');
    }

    public function test_ten_thousand_tokens_have_zero_collisions(): void
    {
        $seen = [];

        for ($i = 0; $i < 10000; $i++) {
            $seen[OpaqueToken::generate()] = true;
        }

        $this->assertCount(10000, $seen);
    }

    public function test_hash_is_sha256_and_never_the_token_itself(): void
    {
        $token = OpaqueToken::generate();
        $hash = OpaqueToken::hash($token);

        $this->assertSame(hash('sha256', $token), $hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        $this->assertNotSame($token, $hash);
    }

    public function test_matches_accepts_only_the_originating_token(): void
    {
        $token = OpaqueToken::generate();
        $hash = OpaqueToken::hash($token);

        $this->assertTrue(OpaqueToken::matches($token, $hash));
        $this->assertFalse(OpaqueToken::matches(OpaqueToken::generate(), $hash));
        $this->assertFalse(OpaqueToken::matches($token, null));
        $this->assertFalse(OpaqueToken::matches($token, ''));
        $this->assertFalse(OpaqueToken::matches('', $hash));
    }
}
