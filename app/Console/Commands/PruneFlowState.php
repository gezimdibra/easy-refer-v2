<?php

namespace App\Console\Commands;

use App\Models\FlowSession;
use App\Models\RateBudget;
use App\Models\TokenNonce;
use Illuminate\Console\Command;

/**
 * Prune the customer flow's short-lived state: expired flow
 * sessions, expired token nonces, and rate-budget rows whose
 * windows closed long ago. Without this sweep, every QR landing
 * leaves a permanent row behind (security verifier M-1).
 */
class PruneFlowState extends Command
{
    protected $signature = 'flow:prune';

    protected $description = 'Delete expired flow sessions, token nonces, and stale rate-budget rows';

    public function handle(): int
    {
        $flows = FlowSession::query()->where('expires_at', '<', now())->delete();
        $nonces = TokenNonce::query()->where('expires_at', '<', now())->delete();
        // Every budget window in the app is at most one day; two
        // days of slack keeps the prune safely behind live windows.
        $budgets = RateBudget::query()->where('window_started_at', '<', now()->subDays(2))->delete();

        $this->info("Pruned {$flows} flow sessions, {$nonces} token nonces, {$budgets} rate budgets.");

        return self::SUCCESS;
    }
}
