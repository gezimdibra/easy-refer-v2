<?php

namespace App\Support;

/**
 * Time-based one-time passwords (RFC 6238 over RFC 4226 HOTP,
 * SHA-1, 30-second period, 6 digits), the mechanism behind two-factor
 * authentication (spec Section 8.6). Implemented directly on PHP's
 * hash_hmac so the project carries no extra dependency for a
 * forty-line standard; the unit tests pin the RFC 6238 vectors.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public const PERIOD_SECONDS = 30;

    public const DIGITS = 6;

    /**
     * A fresh random shared secret, Base32-encoded for authenticator
     * apps (160 bits, the RFC 4226 recommended minimum).
     */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * The current code for a secret, or the code for a given moment.
     */
    public static function code(string $secret, ?int $timestamp = null): string
    {
        $counter = intdiv($timestamp ?? time(), self::PERIOD_SECONDS);
        $key = self::base32Decode($secret);

        // 64-bit big-endian counter, as RFC 4226 requires.
        $binary = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
        $hash = hash_hmac('sha1', $binary, $key, true);

        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 1000000), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a presented code with one period of clock drift allowed
     * on either side. Comparisons are constant-time.
     */
    public static function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        return self::matchingTimestep($secret, $code, $timestamp) !== null;
    }

    /**
     * The timestep (period counter) a presented code belongs to, if
     * it verifies inside the drift window, or null. Callers that must
     * enforce one use per code (RFC 6238 Section 5.2) record the
     * returned timestep and refuse any code at or before it.
     */
    public static function matchingTimestep(string $secret, string $code, ?int $timestamp = null): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return null;
        }

        $at = $timestamp ?? time();
        $current = intdiv($at, self::PERIOD_SECONDS);
        $matched = null;

        foreach ([-1, 0, 1] as $drift) {
            $candidate = $current + $drift;

            if (hash_equals(self::code($secret, $candidate * self::PERIOD_SECONDS), $code)) {
                $matched = $candidate;
            }
        }

        return $matched;
    }

    public static function base32Encode(string $data): string
    {
        $out = '';
        $buffer = 0;
        $bits = 0;

        foreach (str_split($data) as $char) {
            $buffer = ($buffer << 8) | ord($char);
            $bits += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::ALPHABET[($buffer >> $bits) & 31];
            }
        }

        if ($bits > 0) {
            $out .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
        }

        return $out;
    }

    public static function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(rtrim($encoded, '='));
        $out = '';
        $buffer = 0;
        $bits = 0;

        foreach (str_split($encoded) as $char) {
            $value = strpos(self::ALPHABET, $char);

            if ($value === false) {
                continue;
            }

            $buffer = ($buffer << 5) | $value;
            $bits += 5;

            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($buffer >> $bits) & 0xff);
            }
        }

        return $out;
    }
}
