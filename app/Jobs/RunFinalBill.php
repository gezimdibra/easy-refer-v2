<?php

namespace App\Jobs;

use App\Models\Business;
use App\Services\Billing\FinalBillService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * FE-CANCEL-01: the final-bill attempt queued at the End my
 * program tap, dispatched after the closure transaction
 * commits so the request never waits on Square. The closure
 * itself is already complete when this runs; a failure here
 * can never un-end a program.
 */
class RunFinalBill implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array{owed_cents: int, billable_events: int, text_cents: int}  $snapshot
     */
    public function __construct(
        public int $businessId,
        public array $snapshot,
    ) {}

    public function handle(FinalBillService $finalBill): void
    {
        $business = Business::query()->find($this->businessId);

        if ($business === null || ! $business->isClosed()) {
            return;
        }

        $finalBill->attempt($business, $this->snapshot);
    }
}
