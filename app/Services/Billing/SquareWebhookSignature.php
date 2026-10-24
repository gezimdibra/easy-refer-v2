<?php

namespace App\Services\Billing;

/**
 * Square webhook signature verification (spec Section 9.4, audit
 * H1, blueprint Section 2.3): the signature is
 * base64(HMAC-SHA256(notification_url + raw_body, signature_key)),
 * compared in constant time. The notification URL is the exact
 * registered URL built from APP_URL, so the /new mount is part of
 * the signed string on staging. A signature computed over the
 * body alone (v1's legacy scheme) never verifies here.
 *
 * Verification runs before parsing, before logging, before any
 * state change; a failure changes nothing.
 */
final class SquareWebhookSignature
{
    /**
     * Compute the expected signature for a delivery. Exposed so
     * fixtures and the endpoint's tests sign exactly the way
     * Square does.
     */
    public static function sign(string $signatureKey, string $notificationUrl, string $rawBody): string
    {
        return base64_encode(hash_hmac('sha256', $notificationUrl.$rawBody, $signatureKey, true));
    }

    public static function isValid(string $signatureKey, string $notificationUrl, string $rawBody, ?string $signatureHeader): bool
    {
        if ($signatureKey === '' || $signatureHeader === null || $signatureHeader === '') {
            return false;
        }

        return hash_equals(self::sign($signatureKey, $notificationUrl, $rawBody), $signatureHeader);
    }

    /**
     * Verify against a key set (current plus previous) so a
     * signature-key rotation window does not drop deliveries made
     * under the key Square still has registered while the new key
     * rolls out.
     *
     * @param  list<string>  $signatureKeys
     */
    public static function isValidForAnyKey(array $signatureKeys, string $notificationUrl, string $rawBody, ?string $signatureHeader): bool
    {
        foreach ($signatureKeys as $key) {
            if (self::isValid($key, $notificationUrl, $rawBody, $signatureHeader)) {
                return true;
            }
        }

        return false;
    }
}
