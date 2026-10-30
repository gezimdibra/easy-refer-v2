<?php

namespace App\Console\Commands;

use App\Services\Owner\LifecycleMailService;
use Illuminate\Console\Command;

/**
 * The M-5 lifecycle run's command face (design pass Section
 * 9.1): one hourly command for the whole program (drip D1 to
 * D3, allowance A1/A2, card-expired C1), because the stages
 * share the collision and ceiling reads and one run keeps
 * the same-day rules in one place. Scheduled hourly in
 * routes/console.php.
 */
class SendLifecycleMail extends Command
{
    protected $signature = 'lifecycle:mail';

    protected $description = 'Send due M-5 lifecycle emails (setup drip, allowance warnings, expired-card warning)';

    public function handle(LifecycleMailService $lifecycle): int
    {
        $summary = $lifecycle->run();

        $this->info("Lifecycle mail sent {$summary['sent']}, skipped {$summary['skipped']}, deferred {$summary['deferred']}.");

        return self::SUCCESS;
    }
}
