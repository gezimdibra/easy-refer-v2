<?php

namespace App\Console\Commands;

use App\Models\Reward;
use Illuminate\Console\Command;

/**
 * The M-4 reward expiry sweeper (design pass Section 2.7),
 * modeled on ExpireReferrals: available, unheld rewards past
 * their stamped expires_at flip to expired. Reads also apply
 * the transition lazily (Reward::applyTimeExpiry); this keeps
 * the stored state, counts, and reporting honest between reads,
 * the same reason referrals:expire exists. Held rows are never
 * selected: their clock is paused (pass Section 2.3).
 */
class ExpireRewards extends Command
{
    protected $signature = 'rewards:expire';

    protected $description = 'Flip available rewards past their use-by date to expired';

    public function handle(): int
    {
        $flipped = 0;

        Reward::query()
            ->where('state', Reward::STATE_AVAILABLE)
            ->where('hold', false)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->chunkById(200, function ($rewards) use (&$flipped) {
                foreach ($rewards as $reward) {
                    if ($reward->applyTimeExpiry()) {
                        $flipped++;
                    }
                }
            });

        $this->info("Expired {$flipped} reward(s).");

        return self::SUCCESS;
    }
}
