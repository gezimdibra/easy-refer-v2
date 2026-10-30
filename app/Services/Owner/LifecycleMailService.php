<?php

namespace App\Services\Owner;

use App\Mail\AllowanceLastVisitMail;
use App\Mail\AllowancePausedMail;
use App\Mail\CardExpiredMail;
use App\Mail\LifecycleFirstScanMail;
use App\Mail\LifecycleSetupPosterMail;
use App\Mail\LifecycleSetupRewardMail;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\BusinessAlert;
use App\Models\LifecycleEmailSend;
use App\Models\PaymentMethod;
use App\Models\ReferralEvent;
use App\Models\User;
use App\Support\SetupChecklist;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The M-5 lifecycle sender (design pass
 * hidden_files/night-loop/m5-lifecycle-emails-design-pass.md):
 * five emails, six mailables, one hourly sweep.
 *
 * - Drip (Variant A-plus, stall-triggered only): D1 reward
 *   stall at 72h, D2 poster stall at day 5, D3 no-scan stall
 *   at day 10, window closes at day 21, verified owners only
 *   (rule R1: the unverified track's only email is the
 *   welcome nudge).
 * - A1: one free visit left, no card. A2: the allowance is
 *   spent and the code paused for a card, plus ONE reminder
 *   form at day 7. A2 is money-class: it sits outside the
 *   drip-class ceiling and sends even to an unverified owner
 *   (pass Section 7.3's named corner: a paused code is
 *   money-class truth the owner needs at the address on
 *   file).
 * - C1: the card on file has expired, once per card, in the
 *   pre-failure window only (dunning owns the state after a
 *   charge fails).
 *
 * THE PROTOCOL (pass Section 4.1, the WelcomeNudgeService
 * pattern generalized): sweep, never queue. Claim first by
 * inserting the (business, send_key) ledger row; the unique
 * index IS the claim. Re-read the FULL predicate on a fresh
 * business after the claim, immediately before the send; if
 * any leg moved, release the claim (delete the row) without
 * sending. Send synchronously with Mail::send so the body
 * renders at send time from live facts; a queued mailable
 * for a state-asserting body is the OB-04 defect and is
 * forbidden here (no M-5 mailable is ShouldQueue). On a
 * transport exception, release the claim and log.
 *
 * PREDICATES come only from the pass Section 4.2 table:
 * SetupChecklist::for for the reward and poster legs,
 * Business::allowanceLeft()/allowanceUsed() (the settlement
 * walk; never free_visits_remaining), Business::
 * flowIsActive()/flowBlockReason(), Business::hasCardOnFile(),
 * and ActionCenter::cardIsExpired for C1. No parallel
 * definition is computed anywhere in this class. The one
 * printed count D1 might have carried is omitted by design
 * (the pass permits it), so WelcomeMail's steps-left
 * derivation is not duplicated here either.
 *
 * FREQUENCY (pass Section 7.2, all enforced here, none left
 * to copy): every key sends at most once per business;
 * drip-class mail (the welcome nudge, D1, D2, D3, A1, C1)
 * sends at most 2 per rolling 7 days per owner and at least
 * 48 hours apart; drip-class and A2 send only 08:00 to 19:00
 * business-local; a money or event email sent the same
 * business-local day defers a due drip-class email to the
 * next day, and two M-5 emails never share a day for one
 * owner (A2, money-class, does not defer to drip-class);
 * the lifetime bound is 6 M-5 emails plus 1 C1 per card.
 *
 * MEASURE (pass Section 9.5): before selecting new sends,
 * each run stamps step_completed_at on open ledger rows
 * (sent within 30 days) whose completion predicate now
 * reads true. No opens, no clicks, no pixels.
 */
class LifecycleMailService
{
    /** Send window, business-local (pass Section 7.2 rule 3). */
    private const WINDOW_START_HOUR = 8;

    private const WINDOW_END_HOUR = 19;

    /** @var list<string> */
    private const DRIP_KEYS = ['drip_reward', 'drip_poster', 'drip_first_scan'];

    /** @var list<string> audit actions whose same-day send defers drip-class mail (pass Section 7.2 rule 4) */
    private const MONEY_AUDIT_ACTIONS = [
        'billing.dunning_email',
        'billing.forecast_email',
        'billing.expiry_guard_email',
        'business.end_mail_sent',
    ];

    /** @return array{sent: int, skipped: int, deferred: int} */
    public function run(): array
    {
        $summary = ['sent' => 0, 'skipped' => 0, 'deferred' => 0];

        $this->stampCompletions();

        Business::query()
            ->where('is_demo', false)
            ->where('status', 'active')
            ->whereNull('closed_at')
            ->orderBy('id')
            ->each(function (Business $business) use (&$summary): void {
                $owner = $business->owner;

                if ($owner === null) {
                    $summary['skipped']++;

                    return;
                }

                // Money-class first: A2 wins the day over any
                // drip-class mail due in the same run.
                $this->runAllowancePaused($business, $owner, $summary);
                $this->runAllowanceLast($business, $owner, $summary);
                $this->runCardExpired($business, $owner, $summary);
                $this->runDrip($business, $owner, $summary);
            });

        return $summary;
    }

    // ---------------------------------------------------------------
    // Stages
    // ---------------------------------------------------------------

    private function runDrip(Business $business, User $owner, array &$summary): void
    {
        if (! $this->dripCommonEligible($business, $owner)) {
            $summary['deferred']++;

            return;
        }

        $age = $owner->created_at;

        // The priority ladder (pass Section 3.3): at most one
        // drip email per sweep, the earliest unsent step whose
        // predicate holds. The predicates themselves enforce
        // the order (D2/D3 require the flow live, which
        // requires the reward D1 asks for).
        if ($age !== null && $age->lte(now()->subHours(72)) && $age->gt(now()->subDays(21))) {
            $sent = $this->attempt(
                $business,
                $owner,
                'drip_reward',
                class: 'drip',
                predicate: fn (Business $b, User $u): bool => $this->dripCommonEligible($b, $u)
                    && ! (SetupChecklist::for($b, $u)['reward_text'] ?? false),
                make: fn (Business $b, User $u): Mailable => new LifecycleSetupRewardMail($b, $u),
                auditAction: 'lifecycle.drip_sent',
                summary: $summary,
            );

            if ($sent) {
                return;
            }
        }

        if ($age !== null && $age->lte(now()->subDays(5)) && $age->gt(now()->subDays(21))) {
            $sent = $this->attempt(
                $business,
                $owner,
                'drip_poster',
                class: 'drip',
                predicate: fn (Business $b, User $u): bool => $this->dripCommonEligible($b, $u)
                    && $b->flowIsActive()
                    && ! (SetupChecklist::for($b, $u)['poster_downloaded'] ?? false),
                make: fn (Business $b, User $u): Mailable => new LifecycleSetupPosterMail($b),
                auditAction: 'lifecycle.drip_sent',
                summary: $summary,
            );

            if ($sent) {
                return;
            }
        }

        if ($age !== null && $age->lte(now()->subDays(10)) && $age->gt(now()->subDays(21))) {
            $this->attempt(
                $business,
                $owner,
                'drip_first_scan',
                class: 'drip',
                predicate: fn (Business $b, User $u): bool => $this->dripCommonEligible($b, $u)
                    && $b->flowIsActive()
                    && (SetupChecklist::for($b, $u)['poster_downloaded'] ?? false)
                    && ! $this->hasScan($b),
                make: fn (Business $b, User $u): Mailable => new LifecycleFirstScanMail($b),
                auditAction: 'lifecycle.drip_sent',
                summary: $summary,
            );
        }
    }

    private function runAllowanceLast(Business $business, User $owner, array &$summary): void
    {
        $this->attempt(
            $business,
            $owner,
            'allowance_last_one',
            class: 'drip',
            predicate: fn (Business $b, User $u): bool => $this->baseEligible($b)
                && $b->billing_status !== 'past_due'
                && ! $b->hasCardOnFile()
                && $b->allowanceLeft() === 1
                && $b->flowIsActive(),
            make: fn (Business $b, User $u): Mailable => new AllowanceLastVisitMail($b),
            auditAction: 'lifecycle.allowance_sent',
            summary: $summary,
        );
    }

    private function runAllowancePaused(Business $business, User $owner, array &$summary): void
    {
        $predicate = fn (Business $b, User $u): bool => $this->baseEligible($b)
            && $b->billing_status !== 'past_due'
            && $b->flowBlockReason() === 'allowance_exhausted';

        $sent = $this->attempt(
            $business,
            $owner,
            'allowance_paused',
            class: 'money',
            predicate: $predicate,
            make: fn (Business $b, User $u): Mailable => new AllowancePausedMail($b),
            auditAction: 'lifecycle.allowance_sent',
            summary: $summary,
        );

        if ($sent) {
            return;
        }

        // The one reminder form: the first A2 sent at least 7
        // days ago and the pause still holds at send time.
        $first = LifecycleEmailSend::query()
            ->where('business_id', $business->id)
            ->where('send_key', 'allowance_paused')
            ->whereNotNull('sent_at')
            ->first();

        if ($first === null || $first->sent_at === null || $first->sent_at->gt(now()->subDays(7))) {
            return;
        }

        $firstSentAt = $first->sent_at;

        $this->attempt(
            $business,
            $owner,
            'allowance_paused_r7',
            class: 'money',
            predicate: $predicate,
            make: fn (Business $b, User $u): Mailable => new AllowancePausedMail($b, reminder: true, firstSentAt: $firstSentAt),
            auditAction: 'lifecycle.allowance_sent',
            summary: $summary,
        );
    }

    private function runCardExpired(Business $business, User $owner, array &$summary): void
    {
        $card = PaymentMethod::defaultFor($business);

        if ($card === null) {
            return;
        }

        $this->attempt(
            $business,
            $owner,
            'card_expired_'.$card->id,
            class: 'drip',
            predicate: function (Business $b, User $u) use ($card): bool {
                if (! $this->baseEligible($b) || $b->billing_status === 'past_due') {
                    return false;
                }

                $default = PaymentMethod::defaultFor($b);

                return $default !== null
                    && $default->id === $card->id
                    && ActionCenter::cardIsExpired($default);
            },
            make: fn (Business $b, User $u): Mailable => new CardExpiredMail($b, PaymentMethod::defaultFor($b) ?? $card),
            auditAction: 'lifecycle.card_expired_sent',
            auditMeta: ['payment_method_id' => $card->id],
            summary: $summary,
        );
    }

    // ---------------------------------------------------------------
    // The shared claim, re-read, send, release protocol
    // ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $auditMeta
     * @param  array{sent: int, skipped: int, deferred: int}  $summary
     */
    private function attempt(
        Business $business,
        User $owner,
        string $key,
        string $class,
        callable $predicate,
        callable $make,
        string $auditAction,
        array $auditMeta = [],
        array &$summary = ['sent' => 0, 'skipped' => 0, 'deferred' => 0],
    ): bool {
        if (LifecycleEmailSend::query()
            ->where('business_id', $business->id)
            ->where('send_key', $key)
            ->exists()) {
            $summary['skipped']++;

            return false;
        }

        $fresh = $business->fresh() ?? $business;
        $freshOwner = $fresh->owner ?? $owner;

        if (! $predicate($fresh, $freshOwner)) {
            $summary['deferred']++;

            return false;
        }

        if (! $this->classGate($fresh, $freshOwner, $class, excludeKey: null)) {
            $summary['deferred']++;

            return false;
        }

        // The claim: one insert under the unique index. A
        // violation is a lost race with an overlapping sweep,
        // never an error that fails the run.
        try {
            $row = LifecycleEmailSend::create([
                'business_id' => $fresh->id,
                'user_id' => $freshOwner->id,
                'send_key' => $key,
                'sent_at' => now(),
            ]);
        } catch (QueryException) {
            $summary['skipped']++;

            return false;
        }

        $this->afterClaim($fresh, $key);

        // Re-read the FULL predicate and the class gate on a
        // fresh business, immediately before the send: the
        // owner may have acted, the business may have closed,
        // a dunning episode may have opened, between the
        // selection and this instant.
        $recheck = $business->fresh() ?? $business;
        $recheckOwner = $recheck->owner ?? $freshOwner;

        if (! $predicate($recheck, $recheckOwner)
            || ! $this->classGate($recheck, $recheckOwner, $class, excludeKey: $key)) {
            $row->delete();
            $summary['deferred']++;

            return false;
        }

        try {
            Mail::to($recheckOwner->email)->send($make($recheck, $recheckOwner));
        } catch (\Throwable) {
            $row->delete();
            Log::error('Lifecycle email could not be sent; claim released for the next run.', [
                'business_id' => $recheck->id,
                'send_key' => $key,
            ]);
            $summary['skipped']++;

            return false;
        }

        AuditLog::write($auditAction, $recheckOwner, array_merge(['send_key' => $key], $auditMeta), $recheck);
        $summary['sent']++;

        return true;
    }

    /**
     * Extension point between the claim and the post-claim
     * re-read: production code does nothing here; the test
     * suite's stale-state probes override it to move the
     * state inside that window and prove the re-read holds.
     */
    protected function afterClaim(Business $business, string $key): void {}

    // ---------------------------------------------------------------
    // Gates shared by stages
    // ---------------------------------------------------------------

    private function baseEligible(Business $business): bool
    {
        return $business->isActive()
            && ! $business->isClosed()
            && $business->closed_at === null
            && ! $business->isDemo();
    }

    private function dripCommonEligible(Business $business, User $owner): bool
    {
        return $this->baseEligible($business)
            && $owner->hasVerifiedEmail()
            && ! (bool) $business->setup_reminders_opt_out
            && ! ($business->billing_status === 'past_due' && $business->dunningPausedAt());
    }

    /**
     * The class rules of pass Section 7.2: the send window
     * for every M-5 email; for drip-class additionally the
     * rolling ceiling (counting the welcome nudge by its own
     * marker, pass Section 9.2), the 48-hour spacing, the
     * same-day money collision, and the one-M-5-per-day rule.
     * A2 (money class) is exempt from the ceiling and never
     * defers to drip-class mail; it still obeys the window.
     */
    private function classGate(Business $business, User $owner, string $class, ?string $excludeKey): bool
    {
        $local = $business->localTime(now());

        if ($local->hour < self::WINDOW_START_HOUR || $local->hour >= self::WINDOW_END_HOUR) {
            return false;
        }

        if ($class === 'money') {
            return true;
        }

        // Two M-5 emails never share a business-local day for
        // one owner (any class: an A2 sent this morning holds
        // today's drip-class mail until tomorrow).
        if ($this->anyM5SentOnLocalDay($business, $excludeKey)) {
            return false;
        }

        // A money or event email already sent today defers
        // the drip-class email to the next day.
        if ($this->moneyMailSentOnLocalDay($business)) {
            return false;
        }

        // The rolling ceiling over the drip class, the nudge
        // counted by its users.welcome_nudge_sent_at marker.
        $windowStart = now()->subDays(7);
        $sentTimes = LifecycleEmailSend::query()
            ->where('business_id', $business->id)
            ->whereNotNull('sent_at')
            ->where('sent_at', '>', $windowStart)
            ->where(fn ($q) => $q
                ->whereIn('send_key', self::DRIP_KEYS)
                ->orWhere('send_key', 'allowance_last_one')
                ->orWhere('send_key', 'like', 'card_expired_%'))
            ->when($excludeKey !== null, fn ($q) => $q->where('send_key', '!=', $excludeKey))
            ->pluck('sent_at');

        if ($owner->welcome_nudge_sent_at !== null && $owner->welcome_nudge_sent_at->gt($windowStart)) {
            $sentTimes->push($owner->welcome_nudge_sent_at);
        }

        if ($sentTimes->count() >= 2) {
            return false;
        }

        $latest = $sentTimes
            ->map(fn ($t) => $t instanceof Carbon ? $t : Carbon::parse((string) $t))
            ->max();

        if ($latest instanceof Carbon && $latest->gt(now()->subHours(48))) {
            return false;
        }

        return true;
    }

    private function anyM5SentOnLocalDay(Business $business, ?string $excludeKey): bool
    {
        [$startUtc, $endUtc] = $this->localDayBoundsUtc($business);

        return LifecycleEmailSend::query()
            ->where('business_id', $business->id)
            ->whereNotNull('sent_at')
            ->whereBetween('sent_at', [$startUtc, $endUtc])
            ->when($excludeKey !== null, fn ($q) => $q->where('send_key', '!=', $excludeKey))
            ->exists();
    }

    private function moneyMailSentOnLocalDay(Business $business): bool
    {
        [$startUtc, $endUtc] = $this->localDayBoundsUtc($business);

        if (AuditLog::query()
            ->where('business_id', $business->id)
            ->whereIn('action', self::MONEY_AUDIT_ACTIONS)
            ->whereBetween('created_at', [$startUtc, $endUtc])
            ->exists()) {
            return true;
        }

        return BusinessAlert::query()
            ->where('business_id', $business->id)
            ->where('kind', 'first_visit')
            ->whereBetween('created_at', [$startUtc, $endUtc])
            ->exists();
    }

    /** @return array{0: Carbon, 1: Carbon} the business-local day, as UTC bounds */
    private function localDayBoundsUtc(Business $business): array
    {
        $local = $business->localTime(now());

        return [
            $local->copy()->startOfDay()->setTimezone('UTC'),
            $local->copy()->endOfDay()->setTimezone('UTC'),
        ];
    }

    private function hasScan(Business $business): bool
    {
        return ReferralEvent::query()
            ->where('business_id', $business->id)
            ->where('event_type', 'scan')
            ->exists();
    }

    // ---------------------------------------------------------------
    // MEASURE: step_completed_at stamps (pass Section 9.5)
    // ---------------------------------------------------------------

    private function stampCompletions(): void
    {
        LifecycleEmailSend::query()
            ->whereNotNull('sent_at')
            ->whereNull('step_completed_at')
            ->where('sent_at', '>=', now()->subDays(30))
            ->orderBy('id')
            ->each(function (LifecycleEmailSend $row): void {
                $business = $row->business;
                $owner = $row->user;

                if ($business === null || $owner === null) {
                    return;
                }

                if ($this->completionReadsTrue($row, $business, $owner)) {
                    $row->forceFill(['step_completed_at' => now()])->save();
                }
            });
    }

    private function completionReadsTrue(LifecycleEmailSend $row, Business $business, User $owner): bool
    {
        $fresh = $business->fresh() ?? $business;

        return match (true) {
            $row->send_key === 'drip_reward' => (bool) (SetupChecklist::for($fresh, $owner)['reward_text'] ?? false),
            $row->send_key === 'drip_poster' => (bool) (SetupChecklist::for($fresh, $owner)['poster_downloaded'] ?? false),
            $row->send_key === 'drip_first_scan' => $this->hasScan($fresh),
            // A1 completing means the card arrived; the pause
            // arriving anyway is recorded as not-completed
            // (pass Section 9.5), so only the card stamps it,
            // here and for both A2 forms.
            $row->send_key === 'allowance_last_one',
            $row->send_key === 'allowance_paused',
            $row->send_key === 'allowance_paused_r7' => $fresh->hasCardOnFile(),
            str_starts_with($row->send_key, 'card_expired_') => $this->cardWasReplaced($row, $fresh),
            default => false,
        };
    }

    private function cardWasReplaced(LifecycleEmailSend $row, Business $business): bool
    {
        $keyedId = (int) substr($row->send_key, strlen('card_expired_'));
        $default = PaymentMethod::defaultFor($business);

        return $default !== null && $default->id !== $keyedId;
    }
}
