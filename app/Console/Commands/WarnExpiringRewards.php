<?php

namespace App\Console\Commands;

use App\Jobs\SendRewardExpiryWarningSms;
use App\Models\Reward;
use Illuminate\Console\Command;

/**
 * The M-4 warning sweep (design pass Section 2.4): once a day,
 * every still-claimable reward inside its warning window that
 * has never been warned gets its one warning text dispatched.
 * All send-time truth lives in the job (state, hold, opt-out,
 * and the window are re-checked there); this command is only
 * the selection. Held rows are not selected: their clock is
 * paused, and a release guarantees a fresh window the next
 * daily run will see.
 */
class WarnExpiringRewards extends Command
{
    protected $signature = 'rewards:warn-expiring';

    protected $description = 'Dispatch the one expiry warning text for rewards nearing their use-by date';

    public function handle(): int
    {
        $warningDays = (int) config('referrals.reward_expiry_warning_days', 30);
        $sent = 0;

        Reward::query()
            ->where('state', Reward::STATE_AVAILABLE)
            ->where('hold', false)
            ->whereNull('expiry_notice_sent_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays($warningDays))
            ->chunkById(200, function ($rewards) use (&$sent) {
                foreach ($rewards as $reward) {
                    SendRewardExpiryWarningSms::dispatch($reward->id);
                    $sent++;
                }
            });

        $this->info("Dispatched {$sent} reward expiry warning(s).");

        return self::SUCCESS;
    }
}
