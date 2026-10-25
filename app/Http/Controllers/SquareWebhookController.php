<?php

namespace App\Http\Controllers;

use App\Models\WebhookEvent;
use App\Services\Billing\SquareWebhookHandler;
use App\Services\Billing\SquareWebhookSignature;
use App\Services\Billing\WebhookEventRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The Square webhook endpoint (spec Section 9.4). Processing
 * order is mandatory: read the raw body; verify the signature
 * over (the exact registered URL built from APP_URL, plus the
 * raw body) in constant time; only after verification parse,
 * record, and handle. A failed verification returns an error,
 * is counted (an aggregate counter, never a row per post, so a
 * forged-post flood cannot fill the database), and changes no
 * state. Without a configured signature key nothing is
 * accepted at all.
 *
 * A verified delivery is recorded through the dedupe recorder:
 * a duplicate is acknowledged and ignored, except when the
 * first delivery's handling never completed (processed_at
 * still null), in which case the redelivery completes it.
 * Handlers are state-monotonic, so handling the same event
 * twice can never double-apply an effect.
 */
class SquareWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookEventRecorder $recorder, SquareWebhookHandler $handler): Response
    {
        $signatureKey = (string) config('services.square.webhook_signature_key');

        if ($signatureKey === '') {
            return response('Forbidden', 403);
        }

        $rawBody = (string) $request->getContent();
        $notificationUrl = route('webhooks.square');

        if (! SquareWebhookSignature::isValid($signatureKey, $notificationUrl, $rawBody, $request->header('x-square-hmacsha256-signature'))) {
            Cache::increment('square_webhook_signature_failures');
            Log::warning('Square webhook signature rejected', ['ip' => $request->ip()]);

            return response('Forbidden', 403);
        }

        $event = json_decode($rawBody, true);

        // Square's envelope carries the delivery id as the
        // top-level `event_id` — there is no top-level `id`
        // (release 13's live test event proved it: verified,
        // acked 200, recorded nothing while this gate read
        // `id`). No fallback to `id` is kept: Square has never
        // sent that key, and accepting it would silently mask
        // the next envelope drift instead of logging it.
        $eventId = is_array($event) && is_string($event['event_id'] ?? null) ? $event['event_id'] : '';

        if (! is_array($event) || $eventId === '' || empty($event['type'])) {
            Log::warning('Square webhook delivery verified but carried no event id or type; acknowledged without recording.');

            return response('', 200);
        }

        $record = $recorder->recordIfNew(
            'square',
            $eventId,
            (string) $event['type'],
            true,
            self::summary($event),
        );

        if ($record === null) {
            $record = WebhookEvent::query()
                ->where('provider', 'square')
                ->where('event_id', $eventId)
                ->first();

            if ($record === null || $record->processed_at !== null) {
                return response('', 200);
            }
        }

        try {
            $handler->handle($event);
        } catch (\Throwable $e) {
            // The row stays recorded and unprocessed; the error
            // response makes Square redeliver, and the
            // redelivery completes the handling above.
            Log::error('Square webhook handling failed; the delivery stays unprocessed for redelivery.', [
                'event_id' => $eventId,
                'event_type' => (string) $event['type'],
                'exception' => $e->getMessage(),
            ]);

            return response('Handling failed', 500);
        }

        $record->forceFill(['processed_at' => now()])->save();

        return response('', 200);
    }

    /**
     * The redacted-on-write summary the recorder retains: IDs,
     * statuses, and amounts only, never the full payload
     * (spec Section 9.4).
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private static function summary(array $event): array
    {
        $object = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];
        $summary = ['event_type' => (string) $event['type']];

        if (is_array($object['payment'] ?? null)) {
            $summary['payment_id'] = (string) ($object['payment']['id'] ?? '');
            $summary['payment_status'] = (string) ($object['payment']['status'] ?? '');
        }

        if (is_array($object['refund'] ?? null)) {
            $summary['refund_id'] = (string) ($object['refund']['id'] ?? '');
            $summary['refund_status'] = (string) ($object['refund']['status'] ?? '');
            $summary['amount_cents'] = (int) ($object['refund']['amount_money']['amount'] ?? 0);
        }

        if (is_array($object['dispute'] ?? null)) {
            $summary['dispute_id'] = (string) ($object['dispute']['id'] ?? '');
            $summary['dispute_status'] = (string) ($object['dispute']['status'] ?? '');
            $summary['amount_cents'] = (int) ($object['dispute']['amount_money']['amount'] ?? 0);
        }

        return $summary;
    }
}
