<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\Billing\SettlementService;
use Illuminate\Console\Command;

/**
 * The nightly billing collection step (spec Section 9.5): for
 * every business, settle the valuation walk and persist the
 * outcomes (coverage per event, free-visit credit consumption),
 * so the monthly charge and the dashboard read the same ledger.
 * Scheduling is wired by the deploy lane; this command is the
 * runnable unit.
 */
class SettleBilling extends Command
{
    protected $signature = 'billing:settle';

    protected $description = 'Persist settlement outcomes (coverage and free-credit use) for every business';

    public function handle(SettlementService $settlement): int
    {
        $settled = 0;

        Business::query()->chunkById(100, function ($businesses) use ($settlement, &$settled) {
            foreach ($businesses as $business) {
                $settlement->settleAndPersist($business);
                $settled++;
            }
        });

        $this->info("Settled {$settled} business(es).");

        return self::SUCCESS;
    }
}
