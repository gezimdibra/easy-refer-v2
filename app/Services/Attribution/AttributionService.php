<?php

namespace App\Services\Attribution;

use App\Models\AttributionEvent;
use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The Section 16 attribution system: the er_attrib first-touch
 * cookie (server-side, functional classification), the signup-time
 * write onto the business row, and the E1 to E8 event records.
 * Business value and currency are never attached to an event
 * (Section 16 prohibition); event IDs are first-party UUIDs the ad
 * platforms deduplicate browser and server copies on.
 */
class AttributionService
{
    /**
     * The event map (spec Section 16): key => [name, channel].
     * Channels: browser (also sent client-side with consent), server
     * (server-side only, delivered through the database queue).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const EVENTS = [
        'E1' => ['landing_view', 'browser'],
        'E2' => ['signup_start', 'browser'],
        'E3' => ['signup_complete', 'server'],
        'E4' => ['email_verified', 'server'],
        'E5' => ['card_on_file', 'server'],
        'E6' => ['qr_first_scan', 'server'],
        'E7' => ['first_verified_visit', 'server'],
        'E8' => ['first_charge', 'server'],
    ];

    public function cookieName(): string
    {
        return (string) config('attribution.cookie_name');
    }

    public function consentCookieName(): string
    {
        return (string) config('attribution.consent_cookie_name');
    }

    /**
     * Whether capture is allowed for this request: a visitor who
     * declined on the consent banner is never captured (Section 16:
     * declining deletes the cookie and stops capture).
     */
    public function captureAllowed(Request $request): bool
    {
        return $request->cookie($this->consentCookieName()) !== 'declined';
    }

    /**
     * Extract the attribution payload from a landing request, or
     * null when the request carries no UTM parameter and no click
     * ID (an external referrer alone never tags; its host is only
     * captured into a payload a real tag started).
     *
     * @return array<string, ?string>|null
     */
    public function extract(Request $request): ?array
    {
        $payload = [
            'source' => $request->query('utm_source'),
            'medium' => $request->query('utm_medium'),
            'campaign' => $request->query('utm_campaign'),
            'term' => $request->query('utm_term'),
            'content' => $request->query('utm_content'),
            'gclid' => $request->query('gclid'),
            'fbclid' => $request->query('fbclid'),
            'ttclid' => $request->query('ttclid'),
            'landing_path' => $request->path() === '' ? '/' : '/'.ltrim($request->path(), '/'),
            'referrer_host' => null,
        ];

        $referer = $request->headers->get('referer');
        if (is_string($referer) && $referer !== '') {
            $host = parse_url($referer, PHP_URL_HOST);
            if (is_string($host) && $host !== $request->getHost()) {
                $payload['referrer_host'] = $host;
            }
        }

        $tagged = collect($payload)->except(['landing_path', 'referrer_host'])->filter()->isNotEmpty();

        if (! $tagged) {
            return null;
        }

        // The payload rides a client cookie into string(255) acq_
        // columns at signup; bound every value to the column width
        // here so an over-long tag can never fail a production
        // insert after the account rows have committed (security
        // verifier m-6, invisible on SQLite).
        return array_map(
            static fn ($value) => is_string($value) ? mb_substr($value, 0, 255) : $value,
            $payload
        );
    }

    /**
     * The payload already captured for this visitor, if any.
     *
     * @return array<string, ?string>|null
     */
    public function captured(Request $request): ?array
    {
        $raw = $request->cookie($this->cookieName());

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Persist the first touch on the business at signup, exactly
     * once (Section 16): written when the account is created, never
     * overwritten by a later touch.
     *
     * @param  array<string, ?string>  $payload
     */
    public function captureForBusiness(Business $business, array $payload): void
    {
        if ($business->acq_captured_at !== null) {
            return;
        }

        $business->forceFill([
            'acq_source' => $payload['source'] ?? null,
            'acq_medium' => $payload['medium'] ?? null,
            'acq_campaign' => $payload['campaign'] ?? null,
            'acq_term' => $payload['term'] ?? null,
            'acq_content' => $payload['content'] ?? null,
            'acq_gclid' => $payload['gclid'] ?? null,
            'acq_fbclid' => $payload['fbclid'] ?? null,
            'acq_ttclid' => $payload['ttclid'] ?? null,
            'acq_landing_path' => $payload['landing_path'] ?? null,
            'acq_referrer_host' => $payload['referrer_host'] ?? null,
            'acq_captured_at' => now(),
        ])->save();
    }

    /**
     * Record one milestone event. Returns the record, whose event_id
     * is the deduplication key the platforms reconcile on.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(string $eventKey, ?Business $business = null, array $metadata = []): AttributionEvent
    {
        [$name, $channel] = self::EVENTS[$eventKey];

        return AttributionEvent::create([
            'business_id' => $business?->id,
            'event_key' => $eventKey,
            'event_name' => $name,
            'event_id' => (string) Str::uuid(),
            'metadata' => array_merge(['channel' => $channel], $metadata),
        ]);
    }
}
