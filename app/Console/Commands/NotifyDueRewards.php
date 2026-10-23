<?php

namespace App\Console\Commands;

use App\Jobs\SendRewardReadySms;
use App\Models\Reward;
use Illuminate\Console\Command;

/**
 * The vesting sweep (spec Section 3 Step 11): rewards whose
 * vesting date has arrived flip to vested, and the reward-ready
 * SMS goes out for every reward still waiting on its notification,
 * including rewards whose hold was released after vesting (the
 * release path also dispatches directly; the notification_state
 * check in the job makes a double dispatch harmless).
 */
class NotifyDueRewards extends Command
{
    protected $signature = 'rewards:notify-due';

    protected $description = 'Vest due rewards and send their reward-ready texts';

    public function handle(): int
    {
        $sent = 0;

        Reward::query()
            ->where('state', Reward::STATE_PENDING_VESTING)
            ->where('available_at', '<=', now())
            ->where('hold', false)
            ->where('notification_state', 'queued')
            ->chunkById(200, function ($rewards) use (&$sent) {
                foreach ($rewards as $reward) {
                    if ($reward->applyVesting()) {
                        SendRewardReadySms::dispatch($reward->id);
                        $sent++;
                    }
                }
            });

        $this->info("Dispatched {$sent} reward-ready text(s).");

        return self::SUCCESS;
    }
}
