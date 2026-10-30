<?php

namespace App\Console\Commands;

use App\Services\Campaigns\StaleQueuedCampaignSweeper;
use Illuminate\Console\Command;

/**
 * The RR-01 sweeper's command face: recipient rows stranded
 * in `queued` by a process that died between CampaignSender's
 * send-transaction commit and its dispatch loop are
 * re-dispatched, once their age clears the grace window. The
 * recipient job is idempotent per row, so a re-dispatch can
 * never double-send or double-bill (proven in
 * StaleQueuedCampaignSweeperTest).
 */
class SweepStaleQueuedCampaigns extends Command
{
    protected $signature = 'campaigns:sweep-stale-queued {--older-than=30 : Only re-dispatch recipients queued at least this many minutes}';

    protected $description = 'Re-dispatch campaign recipients stranded in queued by an interrupted send';

    public function handle(StaleQueuedCampaignSweeper $sweeper): int
    {
        $summary = $sweeper->sweep((int) $this->option('older-than'));

        $this->info("Re-dispatched {$summary['dispatched']} recipient(s) across {$summary['campaigns']} campaign(s).");

        return self::SUCCESS;
    }
}
