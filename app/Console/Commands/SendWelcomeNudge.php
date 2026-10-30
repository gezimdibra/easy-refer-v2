<?php

namespace App\Console\Commands;

use App\Services\Owner\WelcomeNudgeService;
use Illuminate\Console\Command;

/**
 * The welcome nudge run's command face: sends the one-time
 * WelcomeMail to owner accounts at least 24 hours old whose
 * email is still unverified. Scheduled hourly in
 * routes/console.php, so the nudge lands between 24 and 25
 * hours after signup.
 */
class SendWelcomeNudge extends Command
{
    protected $signature = 'accounts:welcome-nudge';

    protected $description = 'Send the one-time welcome nudge to day-old unverified owner accounts';

    public function handle(WelcomeNudgeService $nudge): int
    {
        $summary = $nudge->run();

        $this->info("Welcome nudge sent {$summary['sent']}, skipped {$summary['skipped']}.");

        return self::SUCCESS;
    }
}
