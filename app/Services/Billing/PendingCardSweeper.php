<?php

namespace App\Services\Billing;

use App\Models\PaymentMethod;
use Illuminate\Support\Facades\Log;

/**
 * The pending-card sweeper (billing UI spec Sections 3 and 7
 * step 4): a card save whose Square outcome was unknown leaves
 * its payment_methods row in `pending`; past the threshold,
 * each such row is settled through CardOnFileService's resolver
 * to the truthful state the save actually reached. Beside
 * PendingRecordSweeper in shape and discipline: old rows only,
 * in id order, with the summary logged only when something moved.
 */
class PendingCardSweeper
{
    public function __construct(private CardOnFileService $cards) {}

    /**
     * @return array{adopted: int, failed: int, still_pending: int}
     */
    public function sweep(int $olderThanMinutes = 15): array
    {
        $summary = ['adopted' => 0, 'failed' => 0, 'still_pending' => 0];

        $methods = PaymentMethod::query()
            ->where('state', PaymentMethod::STATE_PENDING)
            ->where('created_at', '<=', now()->subMinutes($olderThanMinutes))
            ->orderBy('id')
            ->get();

        foreach ($methods as $method) {
            $summary[$this->cards->resolvePendingCard($method)]++;
        }

        if ($summary !== ['adopted' => 0, 'failed' => 0, 'still_pending' => 0]) {
            Log::info('Pending card sweeper settled stale card saves.', $summary);
        }

        return $summary;
    }
}
