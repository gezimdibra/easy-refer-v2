<?php

namespace App\Services\Billing;

use App\Contracts\SquareCustomerGateway;
use App\Contracts\SquarePaymentsGateway;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Card on file (spec Sections 9.3 rule 2, 9.6, 9.9; blueprint
 * Section 1.1). The browser's Web Payments SDK produces a
 * single-use token; this service exchanges it at the gateway for
 * a card on file on the business's Square Customer. The local
 * row exists in `pending` with its deterministic key before the
 * call; brand and last4 come from the gateway response only.
 * Saving the first working card is the activation act: it flips
 * billing_status from `none` (or setup) to `active`. Nothing is
 * charged at activation.
 */
class CardOnFileService
{
    public function __construct(
        private SquarePaymentsGateway $gateway,
        private SquareCustomerGateway $customers,
    ) {}

    /**
     * @throws CardOnFileException when the business has no Square Customer and one cannot be provisioned
     * @throws SquareOutcomeUnknown when Square's answer is unknown (the row stays pending)
     */
    public function saveCard(Business $business, string $sourceId, ?User $actor = null): PaymentMethod
    {
        if ($business->square_customer_id === null) {
            $customerId = $this->customers->createCustomerFor($business);

            if ($customerId === null) {
                throw new CardOnFileException(
                    'Business '.$business->id.' has no Square customer yet; card cannot be saved.',
                );
            }

            $business->forceFill(['square_customer_id' => $customerId])->save();
        }

        $method = PaymentMethod::create([
            'business_id' => $business->id,
            'state' => PaymentMethod::STATE_PENDING,
            'is_default' => false,
        ]);

        $method->forceFill([
            'idempotency_key' => 'card_'.$business->id.'_'.$method->id,
        ])->save();

        $result = $this->gateway->saveCard($business, $sourceId, $method->idempotency_key);

        if (! $result->succeeded) {
            $method->forceFill([
                'state' => PaymentMethod::STATE_FAILED,
                'error_code' => $result->errorCode,
            ])->save();

            return $method;
        }

        DB::transaction(function () use ($business, $method, $result, $actor) {
            PaymentMethod::query()
                ->where('business_id', $business->id)
                ->where('state', PaymentMethod::STATE_SUCCEEDED)
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $method->forceFill([
                'state' => PaymentMethod::STATE_SUCCEEDED,
                'square_card_id' => $result->cardId,
                'card_brand' => $result->brand,
                'last4' => $result->last4,
                'exp_month' => $result->expMonth,
                'exp_year' => $result->expYear,
                'is_default' => true,
            ])->save();

            if (in_array($business->billing_status, ['none', 'setup_incomplete'], true)) {
                $business->forceFill(['billing_status' => 'active'])->save();
            }

            AuditLog::write('billing.card_added', $actor, [
                'brand' => $result->brand,
                'last4' => $result->last4,
            ], $business, $method);
        });

        return $method;
    }

    /**
     * Remove a card on file (spec Section 9.9): the row is kept
     * as a removed mirror (financial evidence is retained), the
     * default passes to the newest remaining succeeded card if
     * one exists, and the removal is audited with its actor. With
     * no card left, later batches skip the business and accrue;
     * the customer flow is never blocked.
     */
    public function removeCard(Business $business, PaymentMethod $method, ?User $actor = null): void
    {
        DB::transaction(function () use ($business, $method, $actor) {
            $wasDefault = $method->is_default;

            $method->forceFill([
                'state' => PaymentMethod::STATE_REMOVED,
                'is_default' => false,
            ])->save();

            if ($wasDefault) {
                $next = PaymentMethod::query()
                    ->where('business_id', $business->id)
                    ->where('state', PaymentMethod::STATE_SUCCEEDED)
                    ->latest('id')
                    ->first();

                $next?->forceFill(['is_default' => true])->save();
            }

            AuditLog::write('billing.card_removed', $actor, [
                'brand' => $method->card_brand,
                'last4' => $method->last4,
            ], $business, $method);
        });
    }
}
