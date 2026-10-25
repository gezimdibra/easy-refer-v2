<?php

use App\Console\Commands\ChargeBilling;
use App\Console\Commands\ExpireReferrals;
use App\Console\Commands\NotifyDueRewards;
use App\Console\Commands\PruneFlowState;
use App\Console\Commands\SendDunning;
use App\Console\Commands\SendForecastReminders;
use App\Console\Commands\SettleBilling;
use App\Console\Commands\SweepPendingBilling;
use App\Console\Commands\SweepPendingCards;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The referral lifecycle sweeps (spec Sections 3.1 and 3 Step 11):
// the one-minute scheduler cron is the platform's heartbeat.
Schedule::command(ExpireReferrals::class)->hourly();
Schedule::command(NotifyDueRewards::class)->everyFifteenMinutes();

// Housekeeping for the customer flow's short-lived state (security
// verifier M-1 compounding note): expired flow sessions and token
// nonces are dead weight once past expiry, and rate-budget rows
// are pruned well past every window horizon the app uses (the
// longest is one day).
Schedule::command(PruneFlowState::class)->daily();

// Billing maintenance (billing spec Section 7 step 10: the
// scheduler contract; first two entries wired at release 13,
// the step-10 remainder wired with the W-1 fix lane). The
// pending-card sweeper resolves card saves whose outcome never
// reached us; the dunning run sends the day 0 / day 3 / day 6
// stage mail off the webhook handler's billing.past_due /
// billing.reactivated audit trail. Hourly keeps the day 0
// email same-day, the spec's promise. The pending-charge
// sweeper takes the same hourly rhythm as its card sibling:
// both resolve unknown Square outcomes for rows at least 15
// minutes old, and an unknown charge outcome should not wait
// a day to resolve (EC-33; the Section 9.7 nightly sweep
// remains the backstop).
Schedule::command(SweepPendingCards::class)->hourly();
Schedule::command(SendDunning::class)->hourly();
Schedule::command(SweepPendingBilling::class)->hourly();

// The monthly money run (spec Section 9.5): the nightly
// settle persists the valuation walk (coverage and free-credit
// consumption) so the charge and the dashboard read the same
// ledger; the charge itself runs once per calendar month, on
// the 1st at 03:00, after that day's 02:00 settle — the
// next-charge date the billing page may render derives from
// this contract alone.
Schedule::command(SettleBilling::class)->dailyAt('02:00');
Schedule::command(ChargeBilling::class)->monthlyOn(1, '03:00');

// The owner-facing billing reminders (RK-03): the pre-charge
// forecast email inside the 3 days before the charge and the
// card-expiry guard inside the 30 days before an expiry that
// beats the next charge; each sends once per charge cycle,
// tracked in the audit log, so a daily rhythm is safe.
Schedule::command(SendForecastReminders::class)->dailyAt('08:00');

// Queue drain (email diagnosis, 2026-10-24): QUEUE_CONNECTION
// is `database` and nothing else on this platform runs a
// worker, so queued mail — the welcome email and the
// password-reset notification, both ShouldQueue — sat in the
// jobs table forever (8 stranded jobs on staging, attempts 0).
// A stop-when-empty worker on the scheduler's own heartbeat
// drains whatever has accumulated and exits; withoutOverlapping
// keeps a slow drain from stacking workers on top of itself.
Schedule::command('queue:work --stop-when-empty')->everyMinute()->withoutOverlapping();
