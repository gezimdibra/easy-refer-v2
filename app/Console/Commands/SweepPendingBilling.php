<?php

namespace App\Console\Commands;

use App\Services\Billing\PendingRecordSweeper;
use Illuminate\Console\Command;

/**
 * The pending-charge resolver (spec Section 9.5, EC-33): batch
 * records left pending by an unknown Square outcome are resolved
 * by replaying their persisted idempotency key. Square either
 * returns the original charge (adopted), reports a definitive
 * failure (events released), or has nothing for the key (record
 * superseded, events released). Simulated records are never
 * swept. Scheduling is wired by the deploy lane.
 */
class SweepPendingBilling extends Command
{
    protected $signature = 'billing:sweep-pending {--older-than=15 : Only resolve records pending at least this many minutes}';

    protected $description = 'Resolve pending billing records against Square by idempotency-key replay';

    public function handle(PendingRecordSweeper $sweeper): int
    {
        $summary = $sweeper->sweep((int) $this->option('older-than'));

        $this->info("Adopted {$summary['adopted']}, failed {$summary['failed']}, superseded {$summary['superseded']}, still pending {$summary['still_pending']}.");

        return self::SUCCESS;
    }
}
