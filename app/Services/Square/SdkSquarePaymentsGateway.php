<?php

namespace App\Services\Square;

use App\Contracts\SquarePaymentsGateway;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Services\Billing\CardResult;
use App\Services\Billing\ChargeResult;
use App\Services\Billing\RefundResult;
use App\Services\Billing\SquareOutcomeUnknown;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Psr\Http\Client\ClientInterface;
use Square\Cards\Requests\CreateCardRequest;
use Square\Cards\Requests\ListCardsRequest;
use Square\Environments;
use Square\Exceptions\SquareApiException;
use Square\Payments\Requests\CreatePaymentRequest;
use Square\Refunds\Requests\RefundPaymentRequest;
use Square\SquareClient;
use Square\Types\Card;
use Square\Types\CreateOrderRequest;
use Square\Types\Money;
use Square\Types\Order;
use Square\Types\OrderLineItem;
use Throwable;

/**
 * The real Square payments gateway (spec Section 9, blueprint
 * Section 1), behind the SquarePaymentsGateway contract. The
 * client is built from configuration only, exactly like the SDK
 * customer gateway: environment from SQUARE_ENVIRONMENT, token
 * from the environment, the Phase 4 pinned Square-Version
 * (2026-09-16, the version the installed SDK targets) when set.
 *
 * Failure semantics are the contract's: a SquareApiException is
 * a definitive answer (the payment was rejected, nothing was
 * created) and maps to a failed result carrying the error code
 * and category; any other throwable means the answer is unknown
 * (timeout, transport fault) and becomes SquareOutcomeUnknown,
 * so the caller leaves its record pending for the sweeper.
 * Failures are logged with IDs and error categories only; the
 * token, card data, and payloads are never written (Section 8.8).
 *
 * resolveCharge replays the create with the record's persisted
 * idempotency key: Square's idempotency contract returns the
 * original payment for a repeated key instead of creating a
 * second charge (EC-33), so a replay either adopts the original
 * outcome or completes the one intended charge, never two.
 *
 * resolveCard cannot replay: the card token is single-use and
 * was never stored. It lists the customer's cards instead and
 * adopts a card only when it is the single one Square holds
 * that no local row mirrors and that was created no earlier
 * than the pending save (a small clock-skew allowance aside).
 * Zero candidates means the save never completed; two or more
 * means the answer is not definite, and the row stays pending
 * rather than adopting a guess.
 */
class SdkSquarePaymentsGateway implements SquarePaymentsGateway
{
    public function __construct(private ?ClientInterface $httpClient = null) {}

    public function chargeCardOnFile(Business $business, PaymentMethod $card, int $amountCents, string $idempotencyKey, array $lineItems): ChargeResult
    {
        $token = $this->token($business);

        if ($token === null) {
            return ChargeResult::failed('square_not_configured', 'integration');
        }

        try {
            $client = $this->client($token);

            $order = $this->createOrder($client, $business, $idempotencyKey.'_order', $lineItems);

            $payment = $client->payments->create(
                new CreatePaymentRequest([
                    'sourceId' => (string) $card->square_card_id,
                    'idempotencyKey' => $idempotencyKey,
                    'amountMoney' => $this->money($amountCents),
                    'orderId' => $order,
                    'customerId' => (string) $business->square_customer_id,
                    'locationId' => (string) config('services.square.location_id'),
                    'referenceId' => 'business-'.$business->id,
                ]),
            )->getPayment();
        } catch (SquareApiException $e) {
            Log::error('Square batch charge failed with an API error.', [
                'business_id' => $business->id,
                'status_code' => $e->getStatusCode(),
                'square_errors' => $this->errorSummary($e),
            ]);

            return ChargeResult::failed($this->firstErrorCode($e), $this->firstErrorCategory($e));
        } catch (Throwable $e) {
            Log::error('Square batch charge outcome is unknown.', [
                'business_id' => $business->id,
                'exception' => $e::class,
            ]);

            throw new SquareOutcomeUnknown('Square charge outcome unknown for business '.$business->id, 0, $e);
        }

        $paymentId = $payment?->getId();
        $status = $payment?->getStatus();

        if ($paymentId === null || ! in_array($status, ['COMPLETED', 'APPROVED'], true)) {
            Log::error('Square batch charge returned no completed payment.', [
                'business_id' => $business->id,
                'payment_status' => $status,
            ]);

            return ChargeResult::failed('payment_not_completed', 'processing');
        }

        return ChargeResult::succeeded($paymentId, $order);
    }

    public function resolveCharge(BillingRecord $record): ?ChargeResult
    {
        $business = $record->business;
        $card = $record->paymentMethod;

        if ($business === null || $card === null) {
            return null;
        }

        $lineItems = $record->events()
            ->orderBy('id')
            ->get()
            ->map(fn ($event) => [
                'name' => 'Referral fee',
                'note' => 'referral '.$event->referral_id,
                'amount_cents' => (int) $event->amount_cents,
            ])
            ->all();

        if ($lineItems === []) {
            return null;
        }

        return $this->chargeCardOnFile($business, $card, (int) $record->amount_cents, $record->idempotency_key, $lineItems);
    }

    public function saveCard(Business $business, string $sourceId, string $idempotencyKey): CardResult
    {
        $token = $this->token($business);

        if ($token === null) {
            return CardResult::failed('square_not_configured', 'integration');
        }

        try {
            $card = $this->client($token)->cards->create(
                new CreateCardRequest([
                    'idempotencyKey' => $idempotencyKey,
                    'sourceId' => $sourceId,
                    'card' => new Card(['customerId' => (string) $business->square_customer_id]),
                ]),
            )->getCard();
        } catch (SquareApiException $e) {
            Log::error('Square card save failed with an API error.', [
                'business_id' => $business->id,
                'status_code' => $e->getStatusCode(),
                'square_errors' => $this->errorSummary($e),
            ]);

            return CardResult::failed($this->firstErrorCode($e), $this->firstErrorCategory($e));
        } catch (Throwable $e) {
            Log::error('Square card save outcome is unknown.', [
                'business_id' => $business->id,
                'exception' => $e::class,
            ]);

            throw new SquareOutcomeUnknown('Square card save outcome unknown for business '.$business->id, 0, $e);
        }

        if ($card === null || $card->getId() === null) {
            Log::error('Square card save returned no card.', ['business_id' => $business->id]);

            return CardResult::failed('card_not_created', 'processing');
        }

        return CardResult::succeeded(
            (string) $card->getId(),
            $card->getCardBrand(),
            $card->getLast4(),
            $card->getExpMonth(),
            $card->getExpYear(),
        );
    }

    public function resolveCard(PaymentMethod $method): ?CardResult
    {
        $business = $method->business;

        if ($business === null || $business->square_customer_id === null) {
            return null;
        }

        $token = $this->token($business);

        if ($token === null) {
            return CardResult::failed('square_not_configured', 'integration');
        }

        try {
            $pager = $this->client($token)->cards->list(
                new ListCardsRequest(['customerId' => (string) $business->square_customer_id]),
            );

            $cards = [];
            foreach ($pager as $card) {
                $cards[] = $card;
            }
        } catch (SquareApiException $e) {
            // The list itself was refused; that is no evidence
            // about the card, so the outcome stays unknown.
            Log::error('Square card list failed with an API error during resolution.', [
                'business_id' => $business->id,
                'status_code' => $e->getStatusCode(),
                'square_errors' => $this->errorSummary($e),
            ]);

            throw new SquareOutcomeUnknown('Square card resolution unknown for business '.$business->id, 0, $e);
        } catch (Throwable $e) {
            Log::error('Square card resolution outcome is unknown.', [
                'business_id' => $business->id,
                'exception' => $e::class,
            ]);

            throw new SquareOutcomeUnknown('Square card resolution unknown for business '.$business->id, 0, $e);
        }

        $mirrored = PaymentMethod::query()
            ->where('business_id', $business->id)
            ->whereNotNull('square_card_id')
            ->pluck('square_card_id')
            ->all();

        $earliest = $method->created_at?->copy()->subSeconds(120);

        $candidates = [];
        foreach ($cards as $card) {
            $cardId = $card->getId();

            if ($cardId === null || in_array($cardId, $mirrored, true)) {
                continue;
            }

            $createdAt = $card->getCreatedAt();
            if ($createdAt !== null && $earliest !== null && Carbon::parse($createdAt)->lt($earliest)) {
                continue;
            }

            $candidates[] = $card;
        }

        if (count($candidates) > 1) {
            throw new SquareOutcomeUnknown('Square holds more than one candidate card for the pending save of business '.$business->id);
        }

        if ($candidates === []) {
            return null;
        }

        $card = $candidates[0];

        return CardResult::succeeded(
            (string) $card->getId(),
            $card->getCardBrand(),
            $card->getLast4(),
            $card->getExpMonth(),
            $card->getExpYear(),
        );
    }

    public function refundPayment(string $squarePaymentId, int $amountCents, string $idempotencyKey, ?string $reason = null): RefundResult
    {
        $token = (string) config('services.square.access_token');

        if ($token === '') {
            return RefundResult::failed('square_not_configured', 'integration');
        }

        try {
            $refund = $this->client($token)->refunds->refundPayment(
                new RefundPaymentRequest([
                    'idempotencyKey' => $idempotencyKey,
                    'amountMoney' => $this->money($amountCents),
                    'paymentId' => $squarePaymentId,
                    'reason' => $reason,
                ]),
            )->getRefund();
        } catch (SquareApiException $e) {
            Log::error('Square refund failed with an API error.', [
                'status_code' => $e->getStatusCode(),
                'square_errors' => $this->errorSummary($e),
            ]);

            return RefundResult::failed($this->firstErrorCode($e), $this->firstErrorCategory($e));
        } catch (Throwable $e) {
            Log::error('Square refund outcome is unknown.', ['exception' => $e::class]);

            throw new SquareOutcomeUnknown('Square refund outcome unknown', 0, $e);
        }

        if ($refund === null || $refund->getId() === null) {
            return RefundResult::failed('refund_not_created', 'processing');
        }

        return RefundResult::succeeded((string) $refund->getId());
    }

    /**
     * @param  list<array{name: string, note: ?string, amount_cents: int}>  $lineItems
     */
    private function createOrder(SquareClient $client, Business $business, string $orderKey, array $lineItems): string
    {
        $items = [];
        foreach ($lineItems as $item) {
            $items[] = new OrderLineItem([
                'name' => $item['name'],
                'note' => $item['note'] ?? null,
                'quantity' => '1',
                'basePriceMoney' => $this->money((int) $item['amount_cents']),
            ]);
        }

        $order = $client->orders->create(
            new CreateOrderRequest([
                'idempotencyKey' => $orderKey,
                'order' => new Order([
                    'locationId' => (string) config('services.square.location_id'),
                    'customerId' => (string) $business->square_customer_id,
                    'referenceId' => 'business-'.$business->id,
                    'lineItems' => $items,
                ]),
            ]),
        )->getOrder();

        $orderId = $order?->getId();

        if ($orderId === null) {
            throw new SquareOutcomeUnknown('Square order creation returned no order for business '.$business->id);
        }

        return $orderId;
    }

    private function money(int $amountCents): Money
    {
        return new Money(['amount' => $amountCents, 'currency' => 'USD']);
    }

    private function token(Business $business): ?string
    {
        $token = (string) config('services.square.access_token');

        if ($token === '') {
            Log::error('Square payments attempted without a configured access token.', [
                'business_id' => $business->id,
            ]);

            return null;
        }

        return $token;
    }

    private function client(string $token): SquareClient
    {
        $options = [
            'baseUrl' => config('services.square.environment') === 'production'
                ? Environments::Production->value
                : Environments::Sandbox->value,
            'maxRetries' => 0,
            'client' => $this->httpClient ?? $this->defaultClient(),
        ];

        $version = config('services.square.version');

        return new SquareClient(
            token: $token,
            version: is_string($version) && $version !== '' ? $version : null,
            options: $options,
        );
    }

    private function defaultClient(): ClientInterface
    {
        return new \GuzzleHttp\Client([
            'timeout' => (float) config('services.square.timeout', 10),
            'connect_timeout' => 5.0,
        ]);
    }

    private function firstErrorCode(SquareApiException $e): string
    {
        $summary = $this->errorSummary($e);
        $first = $summary[0] ?? 'square_api_error';

        return explode(':', $first, 2)[1] ?? 'square_api_error';
    }

    private function firstErrorCategory(SquareApiException $e): ?string
    {
        $summary = $this->errorSummary($e);
        $first = $summary[0] ?? null;

        return $first === null ? null : (explode(':', $first, 2)[0] ?? null);
    }

    /**
     * @return list<string>
     */
    private function errorSummary(SquareApiException $e): array
    {
        $body = json_decode($e->getBody() ?? '', true);
        $summary = [];

        if (is_array($body) && is_array($body['errors'] ?? null)) {
            foreach ($body['errors'] as $error) {
                if (is_array($error)) {
                    $summary[] = (string) ($error['category'] ?? 'unknown').':'.(string) ($error['code'] ?? 'unknown');
                }
            }
        }

        return $summary;
    }
}
