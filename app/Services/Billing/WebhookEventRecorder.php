<?php

namespace App\Services\Billing;

use App\Models\WebhookEvent;

/**
 * The dedupe half of the webhook contract (spec Section 9.4,
 * EC-27 and EC-28): the first verified delivery of an event is
 * recorded; a duplicate or replayed delivery inserts nothing
 * and the caller acknowledges and ignores it. Summaries are
 * redacted on the way in, so no full buyer email and no
 * card-shaped digit run is ever retained (audit H1, BILL-30).
 */
class WebhookEventRecorder
{
    /**
     * Record a delivery if it is new. Returns the row, or null
     * when this (provider, event_id) was already recorded.
     *
     * @param  array<string, mixed>  $summary
     */
    public function recordIfNew(string $provider, string $eventId, string $eventType, bool $signatureVerified, array $summary = []): ?WebhookEvent
    {
        $inserted = WebhookEvent::query()->insertOrIgnore([
            'provider' => $provider,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'signature_verified' => $signatureVerified,
            'payload_summary' => json_encode(self::redact($summary)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            return null;
        }

        return WebhookEvent::query()
            ->where('provider', $provider)
            ->where('event_id', $eventId)
            ->first();
    }

    /**
     * Mask emails (local part reduced to its first character)
     * and card-shaped digit runs anywhere inside a summary, at
     * any depth.
     *
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    public static function redact(array $summary): array
    {
        $out = [];
        foreach ($summary as $key => $value) {
            $out[$key] = self::redactValue($value);
        }

        return $out;
    }

    private static function redactValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return self::redact($value);
        }

        if (! is_string($value)) {
            return $value;
        }

        $masked = preg_replace_callback(
            '/([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/',
            fn (array $m) => $m[1].'***@'.$m[2],
            $value,
        );

        return preg_replace('/\b\d{13,19}\b/', '[redacted]', $masked ?? $value);
    }
}
