<?php

namespace Tests\Unit;

use App\Services\Billing\SquareWebhookSignature;
use Tests\TestCase;

/**
 * Webhook signature verification pins (Phase 4 coverage map row
 * 10; spec Section 9.4; BILL-22 and BILL-23): Square's current
 * scheme over notification URL plus raw body verifies; every
 * forgery shape fails, including v1's legacy body-only scheme.
 */
class SquareWebhookSignatureTest extends TestCase
{
    private string $key = 'sq-signature-key-for-tests';

    private string $url = 'https://easy-refer.com/new/webhooks/square';

    private string $body = '{"type":"payment.updated","event_id":"evt_123","data":{"object":{"payment":{"id":"pay_1","status":"COMPLETED"}}}}';

    public function test_valid_signature_passes(): void
    {
        $signature = SquareWebhookSignature::sign($this->key, $this->url, $this->body);

        $this->assertTrue(SquareWebhookSignature::isValid($this->key, $this->url, $this->body, $signature));
    }

    public function test_tampered_body_fails(): void
    {
        $signature = SquareWebhookSignature::sign($this->key, $this->url, $this->body);
        $tampered = str_replace('COMPLETED', 'REFUNDED', $this->body);

        $this->assertFalse(SquareWebhookSignature::isValid($this->key, $this->url, $tampered, $signature));
    }

    public function test_wrong_key_fails(): void
    {
        $signature = SquareWebhookSignature::sign('a-different-key', $this->url, $this->body);

        $this->assertFalse(SquareWebhookSignature::isValid($this->key, $this->url, $this->body, $signature));
    }

    public function test_signature_without_the_notification_url_fails(): void
    {
        // v1's legacy scheme signed the payload only; under the
        // current scheme that signature must never verify.
        $legacy = base64_encode(hash_hmac('sha256', $this->body, $this->key, true));

        $this->assertFalse(SquareWebhookSignature::isValid($this->key, $this->url, $this->body, $legacy));
    }

    public function test_url_mismatch_fails_including_the_mount_prefix(): void
    {
        // Registered at the /new mount; the same body signed for
        // the root URL is a different signature.
        $signature = SquareWebhookSignature::sign($this->key, 'https://easy-refer.com/webhooks/square', $this->body);

        $this->assertFalse(SquareWebhookSignature::isValid($this->key, $this->url, $this->body, $signature));
    }

    public function test_missing_or_empty_signature_and_key_fail(): void
    {
        $signature = SquareWebhookSignature::sign($this->key, $this->url, $this->body);

        $this->assertFalse(SquareWebhookSignature::isValid($this->key, $this->url, $this->body, null));
        $this->assertFalse(SquareWebhookSignature::isValid($this->key, $this->url, $this->body, ''));
        $this->assertFalse(SquareWebhookSignature::isValid('', $this->url, $this->body, $signature));
    }

    public function test_rotation_window_accepts_the_previous_key(): void
    {
        $previousKey = 'sq-previous-signature-key';
        $signature = SquareWebhookSignature::sign($previousKey, $this->url, $this->body);

        $this->assertTrue(SquareWebhookSignature::isValidForAnyKey([$this->key, $previousKey], $this->url, $this->body, $signature));
        $this->assertFalse(SquareWebhookSignature::isValidForAnyKey([$this->key], $this->url, $this->body, $signature));
        $this->assertFalse(SquareWebhookSignature::isValidForAnyKey([], $this->url, $this->body, $signature));
    }
}
