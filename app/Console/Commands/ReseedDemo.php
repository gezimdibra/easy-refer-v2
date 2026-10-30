<?php

namespace App\Console\Commands;

use App\Models\DemoReseedLog;
use App\Services\Demo\DemoService;
use App\Services\Demo\DemoSessionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The demo's nightly reseed (G6 demo Phase 1, Section 11.3):
 * all demo-session-derived rows are deleted, the demo
 * business returns to its seeded configuration at an honest
 * zero, and expired demo sessions are swept (the phone-fate
 * backstop). A failure is logged in the reseed ledger and
 * alerted; two consecutive failures take the entry points
 * down (DemoService::resting) rather than let demo data age
 * unboundedly. A failed reseed is not dangerous: stale demo
 * data can bill nobody and list nowhere.
 */
class ReseedDemo extends Command
{
    protected $signature = 'demo:reseed';

    protected $description = 'Nightly reseed of the Juniper Café demo business';

    public function handle(DemoService $demo, DemoSessionManager $sessions): int
    {
        try {
            $demo->reseed();
            $sessions->sweepExpired();

            DemoReseedLog::create([
                'succeeded' => true,
                'note' => null,
                'created_at' => now(),
            ]);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DemoReseedLog::create([
                'succeeded' => false,
                'note' => substr($e->getMessage(), 0, 500),
                'created_at' => now(),
            ]);

            Log::error('Demo nightly reseed failed.', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }
    }
}
