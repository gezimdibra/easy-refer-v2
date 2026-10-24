<?php

namespace App\Console\Commands;

use App\Models\BillingRecord;
use App\Models\Business;
use App\Services\Billing\MonthlyCharger;
use Illuminate\Console\Command;

/**
 * The monthly charge run (spec Sections 9.5 and 9.9): assemble
 * each business's unbilled billable events into one Square
 * charge on the card on file. Businesses with nothing due, with
 * a sub-minimum total, or without a card are skipped by the
 * charger itself (a missing card raises the owner alert and the
 * amount accrues in the open). While billing.charges_enabled is
 * off, the run produces simulated records only and claims
 * nothing. Scheduling is wired by the deploy lane.
 */
class ChargeBilling extends Command
{
    protected $signature = 'billing:charge';

    protected $description = 'Charge each business its monthly per-referral batch via Square';

    public function handle(MonthlyCharger $charger): int
    {
        $charged = 0;
        $simulated = 0;
        $skipped = 0;

        Business::query()->chunkById(100, function ($businesses) use ($charger, &$charged, &$simulated, &$skipped) {
            foreach ($businesses as $business) {
                $record = $charger->charge($business);

                if ($record === null) {
                    $skipped++;
                } elseif ($record->state === BillingRecord::STATE_SIMULATED) {
                    $simulated++;
                } else {
                    $charged++;
                }
            }
        });

        $this->info("Charged {$charged} business(es), simulated {$simulated}, skipped {$skipped}.");

        return self::SUCCESS;
    }
}
