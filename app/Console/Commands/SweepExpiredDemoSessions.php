<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoSessionManager;
use Illuminate\Console\Command;

/**
 * The demo expiry sweep (A-18 two-key Finding 2): expired
 * demo sessions are deleted on a short cadence, a few
 * minutes at most after expiry, not at the next visit and
 * not at the nightly reseed. The sweep tears down each
 * expired session's derived rows (DemoSessionManager::expire)
 * so the claim rows die with the session record that held
 * the typed phone. Scheduled every minute in
 * routes/console.php; the scheduler's one-minute heartbeat
 * is the platform's shortest existing cadence.
 */
class SweepExpiredDemoSessions extends Command
{
    protected $signature = 'demo:sweep-expired';

    protected $description = 'Delete expired demo sessions and the rows they created';

    public function handle(DemoSessionManager $sessions): int
    {
        $count = $sessions->sweepExpired();

        $this->info("Demo expiry sweep: {$count} expired session(s) deleted.");

        return self::SUCCESS;
    }
}
