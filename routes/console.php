<?php

use App\Console\Commands\ExpireReferrals;
use App\Console\Commands\NotifyDueRewards;
use App\Console\Commands\PruneFlowState;
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
