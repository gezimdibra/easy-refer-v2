<?php

namespace App\Console\Commands;

use App\Services\Billing\PendingCardSweeper;
use Illuminate\Console\Command;

/**
 * The pending-card resolver's command face (billing UI spec
 * Section 7 step 4): card saves left pending by an unknown
 * Square outcome are settled against what Square holds for the
 * customer. Scheduling is wired by the deploy lane, exactly as
 * for billing:sweep-pending.
 */
class SweepPendingCards extends Command
{
    protected $signature = 'billing:sweep-pending-cards {--older-than=15 : Only resolve cards pending at least this many minutes}';

    protected $description = 'Resolve pending card saves against Square';

    public function handle(PendingCardSweeper $sweeper): int
    {
        $summary = $sweeper->sweep((int) $this->option('older-than'));

        $this->info("Adopted {$summary['adopted']}, failed {$summary['failed']}, still pending {$summary['still_pending']}.");

        return self::SUCCESS;
    }
}
