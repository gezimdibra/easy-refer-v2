<?php

namespace App\Services\Claimable;

use App\Contracts\PhoneVerificationGateway;
use App\Models\ClaimablePage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The §4 claim gate: control of the shop's own published channel.
 * A 6-digit code goes to the LISTED phone via the existing Twilio
 * Verify machinery (the PhoneVerificationGateway contract the
 * customer flow uses). A wrong phone therefore cannot complete a
 * claim by construction: the code only ever goes to the number on
 * the shop's public listing, never to a number the claimer types.
 *
 * Budgets (§4 + the coordinator reconciliation ruling: code
 * budgets are ROLLING 24h): max 3 code sends per page in any
 * rolling 24h, max 5 wrong entries per code, one 24h claim-attempt
 * lock after budgets exhaust. The rolling windows are scoped to
 * THIS gate: hits are individual timestamped rows in
 * claim_gate_hits and a hit counts while it is younger than the
 * window, so a send at 23:59 and a send at 00:01 share one window
 * and a lock hit at T blocks until exactly T+24h. RateBudgetService
 * is shared with other surfaces (verify, demo, leads) whose
 * epoch-aligned fixed-window semantics are deliberate there; its
 * behaviour is NOT changed for them — the rolling behaviour lives
 * here, for the claim gate only. Hit volume is self-bounding (the
 * budgets themselves cap sends and wrong entries per page), so
 * rows are not pruned.
 */
class ClaimGateService
{
    public function __construct(
        private PhoneVerificationGateway $gateway,
    ) {}

    private function key(ClaimablePage $page, string $scope): string
    {
        return 'claim_gate_'.$scope.':'.hash('sha256', 'page-'.$page->id);
    }

    /** Hits of $key younger than $windowSeconds (the rolling window). */
    private function rollingCount(string $key, int $windowSeconds): int
    {
        return (int) DB::table('claim_gate_hits')
            ->where('key', $key)
            ->where('created_at', '>', now()->subSeconds($windowSeconds))
            ->count();
    }

    private function rollingHit(string $key): void
    {
        DB::table('claim_gate_hits')->insert([
            'key' => $key,
            'created_at' => now(),
        ]);
    }

    /** The id of the most recent send hit: the current code's epoch. */
    private function currentCodeEpoch(ClaimablePage $page): int
    {
        return (int) (DB::table('claim_gate_hits')
            ->where('key', $this->key($page, 'send'))
            ->max('id') ?? 0);
    }

    public function isLocked(ClaimablePage $page): bool
    {
        return $this->rollingCount($this->key($page, 'lock'), (int) config('claimable.gate_lock_hours') * 3600) > 0;
    }

    /**
     * The moment the current lock lifts (the latest lock hit plus
     * the lock's stated hours), or null when the page is not
     * locked. Copy that names a reopen time renders from this, so
     * the sentence is true under the rolling lock (state-truth).
     */
    public function lockedUntil(ClaimablePage $page): ?Carbon
    {
        if (! $this->isLocked($page)) {
            return null;
        }

        $latest = DB::table('claim_gate_hits')
            ->where('key', $this->key($page, 'lock'))
            ->max('created_at');

        return $latest === null ? null
            : Carbon::parse($latest)->addHours((int) config('claimable.gate_lock_hours'));
    }

    public function sendsUsed(ClaimablePage $page): int
    {
        return $this->rollingCount($this->key($page, 'send'), 86400);
    }

    /**
     * Send a code to the listed phone. Channel: 'sms' | 'call'
     * (§4's text-or-call choice). Returns the gateway outcome.
     */
    public function sendCode(ClaimablePage $page, string $channel): string
    {
        if ($this->isLocked($page)) {
            return 'locked';
        }

        if ($this->sendsUsed($page) >= (int) config('claimable.gate_sends_per_day')) {
            $this->rollingHit($this->key($page, 'lock'));

            return 'locked';
        }

        $result = $this->gateway->start((string) $page->listed_phone_e164, $channel === 'call' ? 'call' : 'sms');

        if (($result['outcome'] ?? null) === 'started') {
            // The send hit IS the new code's epoch: a fresh code
            // resets the wrong-entry budget, because wrong entries
            // are keyed by the latest send's hit id.
            $this->rollingHit($this->key($page, 'send'));
            $page->recordEvent('gate_code_sent', ['channel' => $channel]);
        }

        return (string) ($result['outcome'] ?? 'provider_error');
    }

    /**
     * Check a code against the listed phone. Outcomes: approved |
     * mismatch | expired | locked | provider_error. Wrong entries
     * are budgeted per code (5); exhausting them locks the page's
     * claim attempts for 24h (§4).
     */
    public function checkCode(ClaimablePage $page, string $code): string
    {
        if ($this->isLocked($page)) {
            return 'locked';
        }

        $wrongKey = $this->key($page, 'wrong_'.$this->currentCodeEpoch($page));

        if ($this->rollingCount($wrongKey, 86400) >= (int) config('claimable.gate_wrong_entries_per_code')) {
            $this->rollingHit($this->key($page, 'lock'));

            return 'locked';
        }

        $result = $this->gateway->check((string) $page->listed_phone_e164, $code);
        $outcome = (string) ($result['outcome'] ?? 'provider_error');

        if ($outcome === 'approved') {
            $page->recordEvent('gate_passed');

            return 'approved';
        }

        if ($outcome === 'pending') {
            $this->rollingHit($wrongKey);

            return 'mismatch';
        }

        // The provider deliberately maps an expired/consumed code
        // to the same denial as a wrong code (SMS-08), so 'denied'
        // is a mismatch here; the expired sentence is used only
        // when the 10 minutes are known locally (controller).
        return $outcome === 'provider_error' ? 'provider_error' : 'mismatch';
    }
}
