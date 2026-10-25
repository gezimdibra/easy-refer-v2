<?php

namespace App\Console\Commands;

use App\Services\Billing\ForecastRemindersService;
use Illuminate\Console\Command;

/**
 * The forecast reminders run's command face (RK-03): the
 * pre-charge forecast email (BILL-FC-02) and the card-expiry
 * guard email (BILL-FC-03), each once per charge cycle.
 * Scheduled daily in routes/console.php.
 */
class SendForecastReminders extends Command
{
    protected $signature = 'billing:forecast-reminders';

    protected $description = 'Send the pre-charge forecast and card-expiry guard emails that are due';

    public function handle(ForecastRemindersService $reminders): int
    {
        $summary = $reminders->run();

        $this->info("Forecast {$summary['forecast']}, expiry guard {$summary['expiry_guard']}.");

        return self::SUCCESS;
    }
}
