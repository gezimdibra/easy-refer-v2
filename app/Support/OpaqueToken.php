<?php

namespace App\Support;

/**
 * Opaque public tokens (spec Section 7.1).
 *
 * Every externally referenced object (QR codes, share links, redeem and
 * claim links) is addressed by a token from this generator, never by a
 * sequential ID. Tokens carry 256 bits of CSPRNG entropy (the spec floor
 * is 128), are URL-safe, and are stored only as SHA-256 hashes so a
 * database read yields no usable links.
 */
final class OpaqueToken
{
    public const ENTROPY_BYTES = 32;

    /**
     * Generate a new plaintext token. The plaintext exists only in the
     * printed code, the customer's link, and the request presenting it.
     */
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::ENTROPY_BYTES)), '+/', '-_'), '=');
    }

    /**
     * The value stored in the database for a plaintext token.
     */
    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * Constant-time check of a presented token against a stored hash.
     */
    public static function matches(string $plainToken, ?string $storedHash): bool
    {
        if ($storedHash === null || $storedHash === '') {
            return false;
        }

        return hash_equals($storedHash, self::hash($plainToken));
    }
}
