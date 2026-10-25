<?php

namespace App\Console\Commands;

use App\Services\Billing\DunningService;
use Illuminate\Console\Command;

/**
 * The dunning run's command face (spec Section 9.6): sends
 * the specced past-due emails (day 0, day 3, day 6, and the
 * recovery success email) against the webhook-driven audit
 * trail. Scheduling is wired by the deploy lane with the
 * scheduler contract, exactly as for billing:sweep-pending.
 */
class SendDunning extends Command
{
    protected $signature = 'billing:dunning';

    protected $description = 'Send due dunning emails for past-due businesses';

    public function handle(DunningService $dunning): int
    {
        $summary = $dunning->run();

        $this->info("Day 0 {$summary['day0']}, day 3 {$summary['day3']}, day 6 {$summary['day6']}, success {$summary['success']}, skipped {$summary['skipped']}.");

        return self::SUCCESS;
    }
}
