<?php

namespace App\Services\Billing;

use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * FE-CANCEL-01 (design pass section 4.3): the first-5-visits
 * free allowance is once per OWNER IDENTITY, not once per
 * Business row, so ending a program and signing up again cannot
 * mint a fresh allowance. Identity keys, verified populated at
 * settlement time in this tree:
 *   - the owner user's canonical email (users.canonical_email,
 *     written for every owner at signup), and
 *   - the business's Square customer id (businesses.
 *     square_customer_id, written when a card is first saved).
 * No card fingerprint is stored anywhere in the tree, so that
 * key is NOT used (named here so nobody assumes it is).
 *
 * Prior consumption is proven only by a PERSISTED free_credit
 * billing event on another business sharing a key. The check
 * FAILS OPEN toward granting in every uncertain case (no owner
 * row, no populated key, any query failure): a keying miss
 * grants $10 of free visits, while a false match would take
 * $10 from a genuinely new owner, and the design prices that
 * asymmetry toward granting. Support can restore manually.
 */
class AllowanceOnce
{
    /**
     * The prior business that consumed free credit under the
     * same owner identity, or null when the allowance stands.
     */
    public function priorConsumer(Business $business): ?Business
    {
        try {
            $emails = User::query()
                ->where('business_id', $business->id)
                ->whereNotNull('canonical_email')
                ->pluck('canonical_email')
                ->filter()
                ->unique()
                ->all();

            $candidateIds = collect();

            if ($emails !== []) {
                $candidateIds = $candidateIds->merge(
                    User::query()
                        ->whereIn('canonical_email', $emails)
                        ->where('business_id', '!=', $business->id)
                        ->pluck('business_id'),
                );
            }

            if (filled($business->square_customer_id)) {
                $candidateIds = $candidateIds->merge(
                    Business::query()
                        ->where('square_customer_id', $business->square_customer_id)
                        ->where('id', '!=', $business->id)
                        ->pluck('id'),
                );
            }

            $candidateIds = $candidateIds->filter()->unique()->values();

            if ($candidateIds->isEmpty()) {
                return null;
            }

            $priorId = BillingEvent::query()
                ->whereIn('business_id', $candidateIds->all())
                ->where('coverage', 'free_credit')
                ->orderBy('id')
                ->value('business_id');

            return $priorId === null ? null : Business::query()->find($priorId);
        } catch (\Throwable $e) {
            Log::warning('Allowance-once check failed open toward granting.', [
                'business_id' => $business->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
