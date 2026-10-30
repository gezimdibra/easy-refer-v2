<?php

use App\Console\Commands\ChargeBilling;
use App\Console\Commands\ExpireReferrals;
use App\Console\Commands\ExpireRewards;
use App\Console\Commands\NotifyDueRewards;
use App\Console\Commands\PruneFlowState;
use App\Console\Commands\SendDunning;
use App\Console\Commands\SendForecastReminders;
use App\Console\Commands\SendWelcomeNudge;
use App\Console\Commands\SettleBilling;
use App\Console\Commands\SweepPendingBilling;
use App\Console\Commands\SweepPendingCards;
use App\Console\Commands\SweepStaleQueuedCampaigns;
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

// M-4 (rewards expiry): the hourly time-expiry sweeper keeps
// stored reward state honest between reads, and the daily
// warning sweep dispatches the one T-30 warning text per
// reward entering its last 30 days.
Schedule::command(ExpireRewards::class)->hourly();
Schedule::command(\App\Console\Commands\WarnExpiringRewards::class)->daily();

// The G1 claimable sweeps: 60-day page expiry (P7) and the
// day-7 removal restore-window close (R2/R3), hourly.
Schedule::command(\App\Console\Commands\ClaimableSweep::class)->hourly();

// The demo's nightly reseed (Amendment 63, Section 11.3):
// 08:00 UTC is the low-traffic hour for the demo business's
// own timezone family; demo data returns to an honest zero.
Schedule::command(\App\Console\Commands\ReseedDemo::class)->dailyAt('08:00');

// The demo expiry sweep (A-18 Finding 2): expired demo
// sessions, the typed phone in them, and every row they
// derived are deleted within a few minutes of the 15-minute
// expiry, whether or not the prospect ever returns. Every
// minute is the scheduler heartbeat's shortest cadence.
Schedule::command(\App\Console\Commands\SweepExpiredDemoSessions::class)->everyMinute();

// The RR-01 recovery sweep: CampaignSender dispatches recipient
// jobs after its send transaction commits, so a process dying in
// that window strands rows in `queued` with no job dispatched. The
// sweeper re-dispatches rows still queued and never attempted past
// the 30-minute grace window (the window's derivation lives on the
// sweeper); hourly matches its pending-outcome siblings above.
Schedule::command(SweepStaleQueuedCampaigns::class)->hourly();

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

// The unverified welcome nudge (owner amendment, 2026-10-29):
// WelcomeMail fires exactly once per owner account, at 24+
// hours old, only while the email is still unverified (the
// once-only marker and the deferral states live on
// WelcomeNudgeService). Hourly keeps the send inside the
// 24-to-25-hour window after signup, the same rhythm as the
// other same-day owner mail above.
Schedule::command(SendWelcomeNudge::class)->hourly();

// The M-5 lifecycle emails (design pass, 2026-10-30): the
// stall-triggered setup drip (D1 reward at 72h, D2 poster at
// day 5, D3 no-scan at day 10, window closes day 21), the
// allowance warnings (A1 at one free visit left, A2 at the
// allowance pause plus its one day-7 reminder), and the
// expired-card warning (C1, once per card). One hourly
// command: the stages share the collision and ceiling reads
// (max 2 drip-class per rolling 7 days, 48 hours apart,
// 08:00 to 19:00 business-local, money mail wins the day),
// and every send is claimed in the lifecycle_email_sends
// ledger under its unique (business, send_key) index, its
// predicate re-read after the claim, and sent synchronously
// at sweep time, never queued.
Schedule::command(\App\Console\Commands\SendLifecycleMail::class)->hourly();

// Queue drain (email diagnosis, 2026-10-24): QUEUE_CONNECTION
// is `database` and nothing else on this platform runs a
// worker, so queued mail — at the time the password-reset
// notification and the then-queued welcome email, both
// ShouldQueue — sat in the
// jobs table forever (8 stranded jobs on staging, attempts 0).
// A stop-when-empty worker on the scheduler's own heartbeat
// drains whatever has accumulated and exits; withoutOverlapping
// keeps a slow drain from stacking workers on top of itself.
Schedule::command('queue:work --stop-when-empty')->everyMinute()->withoutOverlapping();
