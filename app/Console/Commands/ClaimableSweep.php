<?php

namespace App\Console\Commands;

use App\Services\Claimable\ClaimableService;
use Illuminate\Console\Command;

/**
 * The G1 time sweeps (§4/§5/§8): pages past their 60 days expire
 * and purge (P7); removal restore windows close at day 7 and
 * purge + suppress (R2/R3); pending-review locks expire at day 7
 * and free their pages (§4/C32 — the sweep leg; reads also derive
 * it, see ClaimableService::expireReviewLock()). Time passing is
 * a state change in this feature, so it runs on the scheduler,
 * never on a page view alone.
 */
class ClaimableSweep extends Command
{
    protected $signature = 'claimable:sweep';

    protected $description = 'Expire due claimable pages and close removal restore windows';

    public function handle(ClaimableService $service): int
    {
        $expired = $service->expireDue();
        $purged = $service->closeRestoreWindows();
        $reviewLocksFreed = $service->expireReviewLocks();

        $this->info("claimable:sweep expired={$expired} removal_windows_closed={$purged} review_locks_expired={$reviewLocksFreed}");

        return self::SUCCESS;
    }
}
